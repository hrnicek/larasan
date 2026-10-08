<?php

declare(strict_types=1);

namespace App\Domain\Placement\Listeners;

use App\Domain\Placement\Events\TaskAttachedToProject;
use App\Domain\Placement\Events\TaskDetachedFromProject;
use App\Domain\Placement\Events\TaskPlacementMoved;
use App\Domain\Placement\Queries\ChannelsForTask;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use App\Domain\Task\Models\Task;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class BroadcastPlacementChange
{
    public function __construct(
        private Dispatcher $events,
        private ChannelsForTask $channels,
    ) {}

    public function handle(TaskAttachedToProject|TaskDetachedFromProject|TaskPlacementMoved $event): void
    {
        $change = match ($event::class) {
            TaskAttachedToProject::class => 'placement.attached',
            TaskDetachedFromProject::class => 'placement.detached',
            TaskPlacementMoved::class => 'placement.moved',
        };

        $actorId = match ($event::class) {
            TaskAttachedToProject::class => $event->attachedById,
            TaskDetachedFromProject::class => $event->detachedById,
            TaskPlacementMoved::class => $event->movedById,
        };

        $workspaceId = Task::withTrashed()->whereKey($event->taskId)->value('workspace_id');

        // The event's project is always included: after a detach it is no longer among the task's current channels.
        $channels = array_values(array_unique([
            "project.{$event->projectId}",
            ...(is_string($workspaceId) ? ($this->channels)($event->taskId, $workspaceId) : []),
        ]));

        $this->events->dispatch(new ViewInvalidated(
            $channels,
            $change,
            'task',
            $event->taskId,
            $actorId,
        ));
    }
}
