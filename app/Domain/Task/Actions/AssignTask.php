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

        /*
         * And they have to be able to open it (TASK-070-017, answering the question
         * TASK-060-012 deferred until a task had places). Membership alone is not reach: a
         * guest holds the projects they were given, so handing one a card inside a project
         * they cannot open would put work in their list that they cannot read, comment on
         * or complete. The same rule refuses a member for a task that appears only in a
         * private project they are not in.
         */
        if ($assignee !== null && ! $assignee->can('view', $task)) {
            throw TaskException::assigneeCannotReachTask();
        }

        if ($task->assignee_id === $assignee?->id) {
            return $task;
        }

        $task->forceFill(['assignee_id' => $assignee?->id])->save();

        $this->events->dispatch(new TaskAssigned($task->id, $task->workspace_id, $assignee?->id, $actor->id));

        return $task;
    }
}
