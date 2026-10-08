<?php

declare(strict_types=1);

namespace App\Domain\Placement\Actions;

use App\Domain\Placement\Events\TaskDetachedFromProject;
use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

final readonly class DetachTaskFromProject
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, Project $project, User $actor): void
    {
        if (! $project->allowsChangesBy($actor, Capability::TaskUpdate)) {
            throw PlacementException::cannotPlaceTasks();
        }

        $detached = DB::transaction(function () use ($task, $project): bool {
            // Serialises detaches of one task, so two concurrent removals cannot strip its last private placement.
            Task::query()->withTrashed()->whereKey($task->id)->lockForUpdate()->first();

            $placement = $project->placements()->where('task_id', $task->id)->first();

            if (! $placement instanceof TaskProjectMembership) {
                return false;
            }

            // Placements decide who can read a task, so losing its only private one would widen that. See ADR-0023.
            if ($project->visibility === ProjectVisibility::Private
                && ! $task->placements()->whereKeyNot($placement->id)->exists()) {
                throw PlacementException::cannotLeaveItsOnlyPrivateProject();
            }

            // Hard delete: a soft-deleted row would still hold UNIQUE(task_id, project_id) and block re-attaching.
            $placement->delete();

            return true;
        });

        if ($detached) {
            $this->events->dispatch(new TaskDetachedFromProject($task->id, $project->id, $actor->id));
        }
    }
}
