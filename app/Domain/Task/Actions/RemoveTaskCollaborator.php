<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Events\TaskCollaboratorRemoved;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskCollaborator;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Take somebody off a task.
 *
 * Taking somebody else off is assigning, and asks `task.assign`. Stepping off yourself asks
 * nothing — the rule `UnfollowTask` keeps: somebody who has lost the capability, or the task,
 * must still be able to leave work they are no longer doing. Removing somebody who is not on the
 * task is a no-op, because the outcome asked for is already true.
 *
 * An id rather than a `User`: a collaborator whose account is gone has no model left to pass,
 * and the row is found through the task, so the id cannot reach another tenant's data.
 */
final readonly class RemoveTaskCollaborator
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, User $actor, int $collaboratorId): void
    {
        if ($actor->id !== $collaboratorId
            && ! $task->workspace->membershipFor($actor)?->allows(Capability::TaskAssign)) {
            throw TaskException::cannotAssignTask();
        }

        $collaboration = $task->collaborations()->where('user_id', $collaboratorId)->first();

        if (! $collaboration instanceof TaskCollaborator) {
            return;
        }

        $collaboration->delete();

        $this->events->dispatch(new TaskCollaboratorRemoved($task->id, $task->workspace_id, $collaboratorId, $actor->id));
    }
}
