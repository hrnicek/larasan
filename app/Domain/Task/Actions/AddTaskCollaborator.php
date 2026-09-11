<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Events\TaskCollaboratorAdded;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskCollaborator;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Put somebody on a task beside its assignee.
 *
 * Putting people on work is assigning it, so this asks `task.assign` and holds a collaborator to
 * every rule `AssignTask` holds an assignee to: a live member of the workspace who can open the
 * task (TASK-070-017).
 */
final readonly class AddTaskCollaborator
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, User $actor, User $collaborator): TaskCollaborator
    {
        if (! $task->workspace->membershipFor($actor)?->allows(Capability::TaskAssign)) {
            throw TaskException::cannotAssignTask();
        }

        if (! $task->workspace->hasActiveMember($collaborator->id)) {
            throw TaskException::collaboratorIsNotAMember();
        }

        if ($collaborator->cannot('view', $task)) {
            throw TaskException::collaboratorCannotReachTask();
        }

        // The one person the task belongs to is not also somebody helping with it.
        if ($task->assignee_id === $collaborator->id) {
            throw TaskException::collaboratorIsTheAssignee();
        }

        $existing = $task->collaborations()->where('user_id', $collaborator->id)->first();

        if ($existing instanceof TaskCollaborator) {
            return $existing;
        }

        try {
            $collaboration = new TaskCollaborator(['task_id' => $task->id, 'user_id' => $collaborator->id]);
            $collaboration->save();
        } catch (UniqueConstraintViolationException) {
            // Two requests at once; `UNIQUE(task_id, user_id)` kept one row, and it is the answer.
            return $task->collaborations()->where('user_id', $collaborator->id)->firstOrFail();
        }

        $this->events->dispatch(new TaskCollaboratorAdded($task->id, $task->workspace_id, $collaborator->id, $actor->id));

        return $collaboration;
    }
}
