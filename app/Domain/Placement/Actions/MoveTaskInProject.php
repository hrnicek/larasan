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

        // The database cannot enforce that the section belongs to the placement's project.
        if ($section !== null && $section->project_id !== $placement->project_id) {
            throw PlacementException::sectionBelongsToAnotherProject();
        }

        $this->assertTheAnchorIsNotTheCard($placement, $target);

        try {
            $moved = $this->place($placement, $section, $target);
        } catch (UniqueConstraintViolationException) {
            // A concurrent move took the same slot; re-read the column, which now includes it. See ADR-0009.
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

    private function assertTheAnchorIsNotTheCard(TaskProjectMembership $placement, PlacementTarget $target): void
    {
        if ($target->after?->is($placement) === true) {
            throw PlacementException::cannotFollowItself();
        }
    }

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
     * @return Collection<int, TaskProjectMembership>
     */
    private function normalise(TaskProjectMembership $placement, ?Section $section): Collection
    {
        $ordered = $this->lockedColumn($placement, $section);

        // Park every row in negative space first so no write collides with a row that has not moved yet.
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
