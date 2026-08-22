<?php

declare(strict_types=1);

namespace App\Domain\Placement\Actions;

use App\Domain\Placement\Events\TaskDetachedFromProject;
use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Take a task out of a project without taking it out of existence (ADR-0003). The placement
 * row is deleted; the task keeps belonging to its workspace, keeps its other placements, and
 * still appears in My Tasks and in search.
 *
 * There is no soft delete to fall back on: a hidden row would still hold
 * `UNIQUE(task_id, project_id)` and its slot, so re-attaching the task would collide with
 * its own tombstone.
 */
final readonly class DetachTaskFromProject
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, Project $project, User $actor): void
    {
        if (! $project->allowsChangesBy($actor, Capability::TaskUpdate)) {
            throw PlacementException::cannotPlaceTasks();
        }

        $placement = $project->placements()->where('task_id', $task->id)->first();

        // Not in this project: nothing to remove, and nothing to announce. A repeated
        // request, or a board that was already stale when the user clicked.
        if (! $placement instanceof TaskProjectMembership) {
            return;
        }

        $placement->delete();

        $this->events->dispatch(new TaskDetachedFromProject($task->id, $project->id, $actor->id));
    }
}
