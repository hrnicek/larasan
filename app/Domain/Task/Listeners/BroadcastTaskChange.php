<?php

declare(strict_types=1);

namespace App\Domain\Task\Listeners;

use App\Domain\Placement\Queries\ChannelsForTask;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Events\TaskCollaboratorAdded;
use App\Domain\Task\Events\TaskCollaboratorRemoved;
use App\Domain\Task\Events\TaskCompleted;
use App\Domain\Task\Events\TaskCreated;
use App\Domain\Task\Events\TaskDeleted;
use App\Domain\Task\Events\TaskReopened;
use App\Domain\Task\Events\TaskUpdated;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class BroadcastTaskChange
{
    public function __construct(
        private Dispatcher $events,
        private ChannelsForTask $channels,
    ) {}

    public function handle(
        TaskCreated|TaskUpdated|TaskCompleted|TaskReopened|TaskAssigned|TaskCollaboratorAdded|TaskCollaboratorRemoved|TaskDeleted $event,
    ): void {
        $change = match ($event::class) {
            TaskCreated::class => 'task.created',
            TaskUpdated::class => 'task.updated',
            TaskCompleted::class => 'task.completed',
            TaskReopened::class => 'task.reopened',
            TaskAssigned::class => 'task.assigned',
            TaskCollaboratorAdded::class => 'task.collaborator_added',
            TaskCollaboratorRemoved::class => 'task.collaborator_removed',
            TaskDeleted::class => 'task.deleted',
        };

        $actorId = match ($event::class) {
            TaskCreated::class => $event->createdById,
            TaskUpdated::class => $event->updatedById,
            TaskCompleted::class => $event->completedById,
            TaskReopened::class => $event->reopenedById,
            TaskAssigned::class => $event->assignedById,
            TaskCollaboratorAdded::class => $event->addedById,
            TaskCollaboratorRemoved::class => $event->removedById,
            TaskDeleted::class => $event->deletedById,
        };

        // Not queued: channels must be resolved from the placement at the time of the change.
        $this->events->dispatch(new ViewInvalidated(
            ($this->channels)($event->taskId, $event->workspaceId),
            $change,
            'task',
            $event->taskId,
            $actorId,
        ));
    }
}
