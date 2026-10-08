<?php

declare(strict_types=1);

namespace App\Domain\Notification\Notifications;

use App\Domain\Notification\Contracts\DeduplicatesNotifications;
use App\Domain\Notification\Contracts\WorkspaceNotification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

final class TaskAssignedNotification extends Notification implements DeduplicatesNotifications, WorkspaceNotification
{
    use BroadcastsToInbox;

    public function __construct(
        private readonly string $taskId,
        private readonly string $workspaceId,
        private readonly int $assignedById,
    ) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toBroadcast(mixed $notifiable): BroadcastMessage
    {
        return $this->inboxBroadcast($notifiable, 'task.assigned');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'task_id' => $this->taskId,
            'assigned_by_id' => $this->assignedById,
        ];
    }

    public function deduplicationKey(): string
    {
        return 'task.assigned:'.$this->taskId.':'.$this->assignedById;
    }

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }
}
