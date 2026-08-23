<?php

declare(strict_types=1);

namespace App\Domain\Notification\Notifications;

use App\Domain\Notification\Contracts\DeduplicatesNotifications;
use App\Domain\Notification\Contracts\WorkspaceNotification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody said something about a task you are watching.
 */
final class CommentPostedNotification extends Notification implements DeduplicatesNotifications, WorkspaceNotification
{
    use BroadcastsToInbox;

    public function __construct(
        private readonly string $commentId,
        private readonly string $taskId,
        private readonly string $workspaceId,
        private readonly int $authorId,
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
        return $this->inboxBroadcast($notifiable, 'comment.posted');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'comment_id' => $this->commentId,
            'task_id' => $this->taskId,
            'author_id' => $this->authorId,
        ];
    }

    /**
     * A comment is created once, so its id is exactly what makes this notification the same
     * notification. A retried job writes nothing new.
     */
    public function deduplicationKey(): string
    {
        return 'comment.posted:'.$this->commentId;
    }

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }
}
