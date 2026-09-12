<?php

declare(strict_types=1);

namespace App\Domain\Notification\Notifications;

use App\Domain\Notification\Contracts\DeduplicatesNotifications;
use App\Domain\Notification\Contracts\WorkspaceNotification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

final class MentionedInCommentNotification extends Notification implements DeduplicatesNotifications, WorkspaceNotification
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
        return $this->inboxBroadcast($notifiable, 'comment.mentioned');
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

    public function deduplicationKey(): string
    {
        return 'comment.mentioned:'.$this->commentId;
    }

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }
}
