<?php

declare(strict_types=1);

namespace App\Domain\Task\Listeners;

use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Models\Task;
use App\Models\User;

/**
 * Being given a task means watching it.
 *
 * Nobody should have to remember to subscribe to their own work, and an assignee who hears
 * nothing about the thing they are responsible for is the failure this rule exists to prevent.
 *
 * Unassignment removes nothing: somebody who was handed a task and then handed it on may still
 * want to know how it ends, and stopping is a button they already have.
 */
final readonly class FollowAssignedTask
{
    public function __construct(private FollowTask $follow) {}

    public function handle(TaskAssigned $event): void
    {
        if ($event->assigneeId === null) {
            return;
        }

        $task = Task::query()->find($event->taskId);
        $assignee = User::query()->find($event->assigneeId);

        if ($task === null || $assignee === null) {
            return;
        }

        // `FollowTask` treats a second follow as the same follow, so this is safe to run for
        // somebody who is already watching — and it still refuses anybody who cannot reach the
        // task, which `AssignTask` has already established.
        $this->follow->handle($task, $assignee);
    }
}
