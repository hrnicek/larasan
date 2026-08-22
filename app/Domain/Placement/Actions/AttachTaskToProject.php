<?php

declare(strict_types=1);

namespace App\Domain\Placement\Actions;

use App\Domain\Placement\Events\TaskAttachedToProject;
use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Put a task in a project. The task is not moved and not copied — it stays a workspace task
 * and gains one more place it appears (ADR-0003).
 *
 * A new placement is ungrouped: in the project, in no column. Dropping it into a column is
 * a separate operation, because attaching and placing are separate decisions.
 */
final readonly class AttachTaskToProject
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, Project $project, User $actor): TaskProjectMembership
    {
        if (! $project->allowsChangesBy($actor, Capability::TaskUpdate)) {
            throw PlacementException::cannotPlaceTasks();
        }

        /*
         * Two valid ids that must not be combined. The foreign keys prove each row exists;
         * only this check proves they belong to the same tenant, and it lives in the Action
         * because a console command and a queued job never pass a FormRequest (ADR-0003).
         */
        if ($task->workspace_id !== $project->workspace_id) {
            throw PlacementException::taskBelongsToAnotherWorkspace();
        }

        $existing = $this->existing($task, $project);

        // Already there. Attaching twice is the same request arriving twice, not a second
        // card — and `UNIQUE(task_id, project_id)` would refuse the row anyway.
        if ($existing !== null) {
            return $existing;
        }

        try {
            $placement = $this->append($task, $project);
        } catch (UniqueConstraintViolationException) {
            /*
             * Either the same task was attached concurrently, or two attaches computed the
             * same slot in the ungrouped bucket. The first is a no-op, the second appends
             * after the winner — and the guard for both is a database constraint rather
             * than a read that could have gone stale (ADR-0009).
             */
            $placement = $this->existing($task, $project) ?? $this->append($task, $project);
        }

        $this->events->dispatch(new TaskAttachedToProject(
            $placement->id,
            $task->id,
            $project->id,
            $actor->id,
        ));

        return $placement;
    }

    private function existing(Task $task, Project $project): ?TaskProjectMembership
    {
        return $project->placements()->where('task_id', $task->id)->first();
    }

    private function append(Task $task, Project $project): TaskProjectMembership
    {
        return DB::transaction(function () use ($task, $project): TaskProjectMembership {
            /*
             * The tail of the ungrouped bucket, locked inside the transaction so a second
             * attach waits rather than computing the same slot. Ordered and limited rather
             * than aggregated, because PostgreSQL refuses `FOR UPDATE` with `max()` — the
             * same restriction `CreateSection` met.
             *
             * An empty bucket has no row to lock, so two first attaches can still collide;
             * the partial unique index catches that and `handle()` retries.
             */
            $last = $project->placements()
                ->whereNull('section_id')
                ->reorder('position', 'desc')
                ->lockForUpdate()
                ->value('position');

            $placement = new TaskProjectMembership([
                'task_id' => $task->id,
                'project_id' => $project->id,
                'section_id' => null,
            ]);

            $placement->position = SparsePosition::append($last === null ? null : (int) $last);
            $placement->save();

            return $placement;
        });
    }
}
