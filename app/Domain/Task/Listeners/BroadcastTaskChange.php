<?php

declare(strict_types=1);

namespace App\Domain\Task\Listeners;

use App\Domain\Placement\Queries\ChannelsForTask;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Events\TaskCompleted;
use App\Domain\Task\Events\TaskCreated;
use App\Domain\Task\Events\TaskDeleted;
use App\Domain\Task\Events\TaskReopened;
use App\Domain\Task\Events\TaskUpdated;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Tell the screens that are showing this task.
 *
 * Not queued, unlike the broadcast it dispatches: the channels depend on where the task is
 * placed **now**, and a listener that ran a second later could answer for a placement that
 * has since changed. Resolving here and queueing the broadcast keeps the slow half on the
 * queue and the true half in the request.
 */
final readonly class BroadcastTaskChange
{
    public function __construct(
        private Dispatcher $events,
        private ChannelsForTask $channels,
    ) {}

    public function handle(
        TaskCreated|TaskUpdated|TaskCompleted|TaskReopened|TaskAssigned|TaskDeleted $event,
    ): void {
        $change = match ($event::class) {
            TaskCreated::class => 'task.created',
            TaskUpdated::class => 'task.updated',
            TaskCompleted::class => 'task.completed',
            TaskReopened::class => 'task.reopened',
            TaskAssigned::class => 'task.assigned',
            TaskDeleted::class => 'task.deleted',
        };

        $actorId = match ($event::class) {
            TaskCreated::class => $event->createdById,
            TaskUpdated::class => $event->updatedById,
            TaskCompleted::class => $event->completedById,
            TaskReopened::class => $event->reopenedById,
            TaskAssigned::class => $event->assignedById,
            TaskDeleted::class => $event->deletedById,
        };

        $this->events->dispatch(new ViewInvalidated(
            ($this->channels)($event->taskId, $event->workspaceId),
            $change,
            'task',
            $event->taskId,
            $actorId,
        ));
    }
}
