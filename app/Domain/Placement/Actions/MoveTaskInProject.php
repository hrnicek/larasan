<?php

declare(strict_types=1);

namespace App\Domain\Placement\Actions;

use App\Domain\Placement\Data\PlacementTarget;
use App\Domain\Placement\Events\TaskPlacementMoved;
use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Ordering\PositionsNeedNormalisation;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * One board drag: this card, in this column, at that place. A null section is the ungrouped
 * bucket, which is a place rather than an absence (ADR-0004), and where in the column the
 * card lands is a `PlacementTarget` rather than a number — the client never sends a
 * position, so a stale board cannot compute one and write two cards into the same slot
 * (ADR-0009).
 *
 * A position is meaningful only inside one `(project, section)` pair, so a card that changes
 * column is given a slot in the new one rather than carrying over a number that meant
 * something somewhere else.
 */
final readonly class MoveTaskInProject
{
    public function __construct(private Dispatcher $events) {}

    public function handle(
        TaskProjectMembership $placement,
        User $actor,
        ?Section $section,
        ?PlacementTarget $target = null,
    ): TaskProjectMembership {
        $target ??= PlacementTarget::end();

        if (! $placement->project->allowsChangesBy($actor, Capability::TaskUpdate)) {
            throw PlacementException::cannotPlaceTasks();
        }

        /*
         * A column of another project would be a card in two boards at once. The database
         * cannot express "the section's project is this placement's project", so the Action
         * does — and it is checked for every caller, not only the HTTP one.
         */
        if ($section !== null && $section->project_id !== $placement->project_id) {
            throw PlacementException::sectionBelongsToAnotherProject();
        }

        $this->assertTheAnchorIsNotTheCard($placement, $target);

        try {
            $moved = $this->place($placement, $section, $target);
        } catch (UniqueConstraintViolationException) {
            /*
             * Two moves computed the same slot. The unique guard made that an error rather
             * than two cards in one place (ADR-0009); this one reads the column again, which
             * now contains the winner, and places itself relative to it.
             */
            $moved = $this->place($placement->refresh(), $section, $target);
        }

        if ($moved) {
            $this->events->dispatch(new TaskPlacementMoved(
                $placement->id,
                $placement->task_id,
                $placement->project_id,
                $section?->id,
                $actor->id,
            ));
        }

        return $placement;
    }

    /**
     * The one refusal that cannot be left to `indexIn()`. A card is not among its own
     * neighbours, so "after itself" would come back as "not in this column" — true, and not
     * what happened.
     */
    private function assertTheAnchorIsNotTheCard(TaskProjectMembership $placement, PlacementTarget $target): void
    {
        if ($target->after?->is($placement) === true) {
            throw PlacementException::cannotFollowItself();
        }
    }

    /**
     * @return bool whether the card actually moved
     */
    private function place(TaskProjectMembership $placement, ?Section $section, PlacementTarget $target): bool
    {
        return DB::transaction(function () use ($placement, $section, $target): bool {
            $ordered = $this->lockedColumn($placement, $section);

            $slot = $this->slotFor($ordered, $placement, $section, $target);

            if ($slot === null) {
                return false;
            }

            try {
                $position = SparsePosition::between($slot['before'], $slot['after']);
            } catch (PositionsNeedNormalisation) {
                /*
                 * The neighbours have closed up, so there is no midpoint left to take. The
                 * column is respread inside this transaction and the slot is recomputed from
                 * the new positions — normalisation is the exception, not the steady state.
                 */
                $ordered = $this->normalise($placement, $section);
                $slot = $this->slotFor($ordered, $placement, $section, $target);

                if ($slot === null) {
                    return false;
                }

                $position = SparsePosition::between($slot['before'], $slot['after']);
            }

            $placement->forceFill([
                'section_id' => $section?->id,
                'position' => $position,
            ])->save();

            return true;
        });
    }

    /**
     * The neighbours the card lands between, or null when it is already there.
     *
     * @param  Collection<int, TaskProjectMembership>  $ordered
     * @return array{before: int|null, after: int|null}|null
     */
    private function slotFor(
        Collection $ordered,
        TaskProjectMembership $placement,
        ?Section $section,
        PlacementTarget $target,
    ): ?array {
        $others = $ordered->reject(fn (TaskProjectMembership $card): bool => $card->is($placement))->values();

        $index = $this->indexIn($others, $target);

        $before = $index === 0 ? null : $others->get($index - 1)?->position;
        $next = $others->get($index)?->position;

        $current = $ordered->search(fn (TaskProjectMembership $card): bool => $card->is($placement));

        // Already in that slot of that column: the same neighbours, in the same order, so
        // there is nothing to write and nothing to announce.
        if ($placement->section_id === $section?->id
            && $current !== false
            && $this->alreadyBetween($ordered, $current, $before, $next)) {
            return null;
        }

        return ['before' => $before, 'after' => $next];
    }

    /**
     * @param  Collection<int, TaskProjectMembership>  $others
     */
    private function indexIn(Collection $others, PlacementTarget $target): int
    {
        if ($target->atFront) {
            return 0;
        }

        if ($target->after === null) {
            return $others->count();
        }

        $anchor = $others->search(fn (TaskProjectMembership $card): bool => $card->is($target->after));

        // The anchor is not in this column's order at all: a board that went stale between
        // the drag and the request.
        if ($anchor === false) {
            throw PlacementException::cardIsNotInThatColumn();
        }

        return $anchor + 1;
    }

    /**
     * @param  Collection<int, TaskProjectMembership>  $ordered
     */
    private function alreadyBetween(Collection $ordered, int $current, ?int $before, ?int $next): bool
    {
        $previousPosition = $current === 0 ? null : $ordered->get($current - 1)?->position;
        $nextPosition = $ordered->get($current + 1)?->position;

        return $previousPosition === $before && $nextPosition === $next;
    }

    /**
     * Every card in the target column, locked and in order, so a second move into it waits
     * instead of reading the same neighbours. The card being moved is included when it is
     * already there, because its own position is part of the order it is moving within.
     *
     * @return Collection<int, TaskProjectMembership>
     */
    private function lockedColumn(TaskProjectMembership $placement, ?Section $section): Collection
    {
        return $this->column($placement, $section)->lockForUpdate()->orderBy('position')->get();
    }

    /** @return HasMany<TaskProjectMembership, Project> */
    private function column(TaskProjectMembership $placement, ?Section $section): HasMany
    {
        $column = $placement->project->placements();

        return $section === null
            ? $column->whereNull('section_id')
            : $column->where('section_id', $section->id);
    }

    /**
     * Rewrite the column's positions to an even spread. Every row is parked in negative
     * space first, so no write can land on a row that has not moved yet — the slot guard
     * would otherwise make the rewrite order load-bearing.
     *
     * @return Collection<int, TaskProjectMembership>
     */
    private function normalise(TaskProjectMembership $placement, ?Section $section): Collection
    {
        $ordered = $this->lockedColumn($placement, $section);

        foreach ($ordered as $index => $card) {
            $card->forceFill(['position' => SparsePosition::parking($index)])->save();
        }

        $spread = SparsePosition::spread($ordered->count());

        foreach ($ordered as $index => $card) {
            $card->forceFill(['position' => $spread[$index]])->save();
        }

        return $ordered;
    }
}
