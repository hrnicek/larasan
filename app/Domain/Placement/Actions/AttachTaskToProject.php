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

final readonly class AttachTaskToProject
{
    private const int ATTEMPTS = 3;

    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, Project $project, User $actor): TaskProjectMembership
    {
        if (! $project->allowsChangesBy($actor, Capability::TaskUpdate)) {
            throw PlacementException::cannotPlaceTasks();
        }

        // Foreign keys cannot enforce a shared workspace, and non-HTTP callers skip FormRequests. See ADR-0003.
        // An unreachable task is refused the same way, since a new placement would make it readable.
        if ($task->workspace_id !== $project->workspace_id || $actor->cannot('view', $task)) {
            throw PlacementException::taskBelongsToAnotherWorkspace();
        }

        $existing = $this->existing($task, $project);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $placement = $this->append($task, $project);
        } catch (UniqueConstraintViolationException) {
            // A concurrent attach of the same task. See ADR-0009.
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
            // The lock every placement write takes first, so CreateTaskInProject cannot deadlock with a move.
            // Under it the end of the bucket cannot change, so no row lock is needed to read it. See ADR-0009.
            Project::query()->whereKey($project->id)->lock('for no key update')->value('id');

            $last = $project->placements()->whereNull('section_id')->max('position');

            $placement = new TaskProjectMembership([
                'task_id' => $task->id,
                'project_id' => $project->id,
                'section_id' => null,
            ]);

            $placement->position = SparsePosition::append($last === null ? null : (int) $last);
            $placement->save();

            return $placement;
        }, self::ATTEMPTS);
    }
}
