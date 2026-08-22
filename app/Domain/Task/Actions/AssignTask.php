<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Assignment is its own operation because it has its own capability: `task.assign` is not
 * `task.update`, and a role may hold one without the other.
 */
final readonly class AssignTask
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, User $actor, ?User $assignee): Task
    {
        if (! $task->workspace->membershipFor($actor)?->allows(Capability::TaskAssign)) {
            throw TaskException::cannotAssignTask();
        }

        /*
         * The assignee has to be a live member of the task's workspace. A user id is a
         * global thing: without this, any account in the installation could be handed work
         * inside a tenant it has never been part of, and would then appear in its filters
         * and notifications.
         */
        if ($assignee !== null && ! $task->workspace->hasActiveMember($assignee->id)) {
            throw TaskException::assigneeIsNotAMember();
        }

        if ($task->assignee_id === $assignee?->id) {
            return $task;
        }

        $task->forceFill(['assignee_id' => $assignee?->id])->save();

        $this->events->dispatch(new TaskAssigned($task->id, $task->workspace_id, $assignee?->id, $actor->id));

        return $task;
    }
}
