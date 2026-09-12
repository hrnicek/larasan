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

final readonly class DetachTaskFromProject
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, Project $project, User $actor): void
    {
        if (! $project->allowsChangesBy($actor, Capability::TaskUpdate)) {
            throw PlacementException::cannotPlaceTasks();
        }

        $placement = $project->placements()->where('task_id', $task->id)->first();

        if (! $placement instanceof TaskProjectMembership) {
            return;
        }

        // Hard delete: a soft-deleted row would still hold UNIQUE(task_id, project_id) and block re-attaching.
        $placement->delete();

        $this->events->dispatch(new TaskDetachedFromProject($task->id, $project->id, $actor->id));
    }
}
