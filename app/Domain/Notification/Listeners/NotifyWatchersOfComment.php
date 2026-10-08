<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Notification\Notifications\CommentPostedNotification;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

final readonly class NotifyWatchersOfComment implements ShouldQueue
{
    public function viaQueue(): string
    {
        return 'notifications';
    }

    public function handle(CommentCreated $event): void
    {
        if ($event->subjectType !== 'task') {
            return;
        }

        $task = Task::query()->find($event->subjectId);

        if ($task === null) {
            return;
        }

        $watchers = $task->followers()->get();

        if ($task->assignee_id !== null && ! $watchers->contains('id', $task->assignee_id)) {
            $assignee = User::query()->find($task->assignee_id);

            if ($assignee !== null) {
                $watchers->push($assignee);
            }
        }

        $recipients = $watchers
            ->reject(fn (User $watcher): bool => $watcher->id === $event->authorId)
            // Mentioned people are notified by NotifyMentionedPeople instead.
            ->reject(fn (User $watcher): bool => in_array($watcher->id, $event->mentionedIds, true))
            ->filter(fn (User $watcher): bool => $watcher->can('view', $task));

        if ($recipients->isEmpty()) {
            return;
        }

        Notifications::send($recipients, $this->notification($event));
    }

    private function notification(CommentCreated $event): Notification
    {
        return new CommentPostedNotification(
            $event->commentId,
            $event->subjectId,
            $event->workspaceId,
            $event->authorId,
        );
    }
}
