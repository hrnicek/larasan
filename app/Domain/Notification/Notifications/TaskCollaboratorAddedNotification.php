<?php

declare(strict_types=1);

namespace App\Domain\Notification\Notifications;

use App\Domain\Notification\Contracts\DeduplicatesNotifications;
use App\Domain\Notification\Contracts\WorkspaceNotification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody put you on a task beside its assignee.
 *
 * Ids and nothing else, as `TaskAssignedNotification` carries: read a week later, it shows the
 * task as it is.
 */
final class TaskCollaboratorAddedNotification extends Notification implements DeduplicatesNotifications, WorkspaceNotification
{
    use BroadcastsToInbox;

    public function __construct(
        private readonly string $taskId,
        private readonly string $workspaceId,
        private readonly int $addedById,
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
        return $this->inboxBroadcast($notifiable, 'task.collaborator_added');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'task_id' => $this->taskId,
            'added_by_id' => $this->addedById,
        ];
    }

    /** Taken off and put back by the same person is one sentence, not two. */
    public function deduplicationKey(): string
    {
        return 'task.collaborator_added:'.$this->taskId.':'.$this->addedById;
    }

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }
}
