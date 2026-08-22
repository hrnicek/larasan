<?php

declare(strict_types=1);

namespace App\Domain\Notification\Notifications;

use App\Domain\Notification\Contracts\WorkspaceNotification;
use Illuminate\Notifications\Notification;

/**
 * Somebody said something about a task you are watching.
 */
final class CommentPostedNotification extends Notification implements WorkspaceNotification
{
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
        return ['database'];
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

    public function workspaceId(): string
    {
        return $this->workspaceId;
    }
}
