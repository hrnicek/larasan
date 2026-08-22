<?php

declare(strict_types=1);

namespace App\Domain\Section\Actions;

use App\Domain\Project\Models\Project;
use App\Domain\Section\Events\SectionMoved;
use App\Domain\Section\Exceptions\SectionException;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Ordering\PositionsNeedNormalisation;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Reordering, expressed as "put this section after that one" (ADR-0009). The client never
 * sends a position, so a stale board cannot compute one from what it last saw and write two
 * sections into the same slot.
 */
final readonly class MoveSection
{
    public function __construct(private Dispatcher $events) {}

    /**
     * @param  Section|null  $after  the section this one goes behind, or null for the front
     */
    public function handle(Section $section, User $actor, ?Section $after): Section
    {
        if (! $section->project->allowsSectionChangesBy($actor, Capability::SectionUpdate)) {
            throw SectionException::cannotManageSections();
        }

        if ($after !== null && $after->project_id !== $section->project_id) {
            throw SectionException::sectionBelongsToAnotherProject();
        }

        if ($after !== null && $after->is($section)) {
            throw SectionException::cannotFollowItself();
        }

        try {
            $moved = $this->place($section, $after);
        } catch (UniqueConstraintViolationException) {
            /*
             * Two moves computed the same midpoint. `UNIQUE(project_id, position)` made
             * that an error instead of two sections in one slot; this one reads the order
             * again, which now contains the winner, and places itself relative to it.
             */
            $moved = $this->place($section->fresh() ?? $section, $after?->fresh());
        }

        if ($moved) {
            $this->events->dispatch(new SectionMoved($section->id, $section->project_id, $after?->id));
        }

        return $section->refresh();
    }

    /**
     * @return bool whether the section actually moved
     */
    private function place(Section $section, ?Section $after): bool
    {
        return DB::transaction(function () use ($section, $after): bool {
            $ordered = $this->lockedOrder($section->project);

            $target = $this->slotFor($ordered, $section, $after);

            if ($target === null) {
                return false;
            }

            try {
                $position = SparsePosition::between($target['before'], $target['after']);
            } catch (PositionsNeedNormalisation) {
                /*
                 * The neighbours have closed up, so there is no midpoint left to take. The
                 * whole project is respread inside this transaction and the slot is
                 * recomputed from the new positions — normalisation is the exception, not
                 * the steady state (ADR-0009).
                 */
                $ordered = $this->normalise($section->project);
                $target = $this->slotFor($ordered, $section, $after);

                if ($target === null) {
                    return false;
                }

                $position = SparsePosition::between($target['before'], $target['after']);
            }

            $section->forceFill(['position' => $position])->save();

            return true;
        });
    }

    /**
     * The neighbours the section lands between, or null when it is already there.
     *
     * @param  Collection<int, Section>  $ordered
     * @return array{before: int|null, after: int|null}|null
     */
    private function slotFor(Collection $ordered, Section $section, ?Section $after): ?array
    {
        $others = $ordered->reject(fn (Section $candidate): bool => $candidate->is($section))->values();

        $index = 0;

        if ($after !== null) {
            $anchor = $others->search(fn (Section $candidate): bool => $candidate->is($after));

            // The anchor is not in this project's order at all: a stale board, or an id
            // from somewhere else. Placing "after" it would otherwise mean the front.
            if ($anchor === false) {
                throw SectionException::sectionBelongsToAnotherProject();
            }

            $index = $anchor + 1;
        }

        $before = $index === 0 ? null : $others[$index - 1]->position;
        $next = $others->get($index)?->position;

        $current = $ordered->search(fn (Section $candidate): bool => $candidate->is($section));

        // Already in that slot: the same neighbours, in the same order, so nothing to write.
        if ($current !== false && $this->alreadyBetween($ordered, $current, $before, $next)) {
            return null;
        }

        return ['before' => $before, 'after' => $next];
    }

    /**
     * @param  Collection<int, Section>  $ordered
     */
    private function alreadyBetween(Collection $ordered, int $current, ?int $before, ?int $next): bool
    {
        $previousPosition = $current === 0 ? null : $ordered[$current - 1]->position;
        $nextPosition = $ordered->get($current + 1)?->position;

        return $previousPosition === $before && $nextPosition === $next;
    }

    /**
     * Every section of the project, locked and in order, so a second move waits instead of
     * reading the same neighbours.
     *
     * @return Collection<int, Section>
     */
    private function lockedOrder(Project $project): Collection
    {
        return $project->sections()->lockForUpdate()->get();
    }

    /**
     * Rewrite the project's positions to an even spread. Every row is parked in negative
     * space first, so no write can land on a row that has not moved yet — the unique
     * constraint would otherwise make the rewrite order load-bearing.
     *
     * @return Collection<int, Section>
     */
    private function normalise(Project $project): Collection
    {
        $ordered = $this->lockedOrder($project);

        foreach ($ordered as $index => $section) {
            $section->forceFill(['position' => SparsePosition::parking($index)])->save();
        }

        $spread = SparsePosition::spread($ordered->count());

        foreach ($ordered as $index => $section) {
            $section->forceFill(['position' => $spread[$index]])->save();
        }

        return $ordered;
    }
}
