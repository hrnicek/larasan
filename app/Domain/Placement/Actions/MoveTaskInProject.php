<?php

declare(strict_types=1);

namespace App\Domain\Placement\Actions;

use App\Domain\Placement\Events\TaskPlacementMoved;
use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Which column a card sits in, per project (ADR-0004). A null section is the ungrouped
 * bucket, which is a place rather than an absence: the list view's default, and where a card
 * lands when it is dragged out of a column.
 *
 * A position is meaningful only inside one `(project, section)` pair, so a card that changes
 * column is given a slot in the new one rather than carrying over a number that meant
 * something somewhere else (ADR-0009).
 */
final readonly class MoveTaskInProject
{
    public function __construct(private Dispatcher $events) {}

    public function handle(TaskProjectMembership $placement, User $actor, ?Section $section): TaskProjectMembership
    {
        $project = $placement->project;

        if (! $project->allowsChangesBy($actor, Capability::TaskUpdate)) {
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

        // Already in that column. Ordering inside it is a different request (TASK-070-008),
        // and rewriting the position here would move a card the user did not move.
        if ($placement->section_id === $section?->id) {
            return $placement;
        }

        try {
            $this->append($placement, $section);
        } catch (UniqueConstraintViolationException) {
            /*
             * Two cards arrived at the tail of the same column together. The slot guard made
             * that an error rather than two cards in one place (ADR-0009); this one reads
             * the tail again, which now contains the winner, and appends after it.
             */
            $this->append($placement, $section);
        }

        $this->events->dispatch(new TaskPlacementMoved(
            $placement->id,
            $placement->task_id,
            $placement->project_id,
            $section?->id,
            $actor->id,
        ));

        return $placement;
    }

    private function append(TaskProjectMembership $placement, ?Section $section): void
    {
        DB::transaction(function () use ($placement, $section): void {
            /*
             * The tail of the target column, locked inside the transaction so a second move
             * into it waits instead of computing the same slot. Ordered and limited rather
             * than aggregated, because PostgreSQL refuses `FOR UPDATE` with `max()`.
             *
             * An empty column has no row to lock, so two first moves into one can still
             * collide; the slot guard catches that and `handle()` retries.
             */
            $last = $placement->project->placements()
                ->when(
                    $section === null,
                    fn ($query) => $query->whereNull('section_id'),
                    fn ($query) => $query->where('section_id', $section?->id),
                )
                ->whereKeyNot($placement->id)
                ->reorder('position', 'desc')
                ->lockForUpdate()
                ->value('position');

            $placement->forceFill([
                'section_id' => $section?->id,
                'position' => SparsePosition::append($last === null ? null : (int) $last),
            ])->save();
        });
    }
}
