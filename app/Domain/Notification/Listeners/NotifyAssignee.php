<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Notifications\TaskAssignedNotification;
use App\Domain\Task\Events\TaskAssigned;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tell the person a task was given to.
 *
 * Nobody is told about their own doing: an inbox full of one's own actions is an inbox nobody
 * reads, and picking a task up yourself is not news. Unassignment notifies nobody at all —
 * there is no one to tell, and the person it was taken from finds out from the task.
 */
final readonly class NotifyAssignee implements ShouldQueue
{
    /**
     * The `notifications` queue Horizon already supervises, below `broadcasts` (ADR-0008): a
     * slow inbox must never delay a board update, and nobody's request should wait on somebody
     * else's notification.
     *
     * The connection is left to configuration. Pinning it here would send the tests' jobs to a
     * Redis nobody asked them to need.
     */
    public function viaQueue(): string
    {
        return 'notifications';
    }

    public function handle(TaskAssigned $event): void
    {
        if ($event->assigneeId === null || $event->assigneeId === $event->assignedById) {
            return;
        }

        $assignee = User::query()->find($event->assigneeId);

        $assignee?->notify(new TaskAssignedNotification(
            $event->taskId,
            $event->workspaceId,
            $event->assignedById,
        ));
    }
}
