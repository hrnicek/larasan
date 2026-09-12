<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Notifications\TaskAssignedNotification;
use App\Domain\Task\Events\TaskAssigned;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

final readonly class NotifyAssignee implements ShouldQueue
{
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
