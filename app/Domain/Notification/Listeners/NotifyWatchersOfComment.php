<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Notification\Notifications\CommentPostedNotification;
use App\Domain\Task\Models\Task;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

/**
 * Tell the people watching a task that somebody said something about it.
 *
 * Only tasks for now: a comment can hang from anything, but nothing else has followers yet, and
 * a listener that guessed at the others would be guessing about who should hear from them.
 */
final readonly class NotifyWatchersOfComment
{
    public function handle(CommentCreated $event): void
    {
        if ($event->subjectType !== 'task') {
            return;
        }

        $task = Task::query()->find($event->subjectId);

        if ($task === null) {
            return;
        }

        // Never the author. Being told about your own comment is the fastest way to teach
        // somebody to ignore the inbox entirely.
        $watchers = $task->followers()
            ->where('users.id', '!=', $event->authorId)
            ->get();

        if ($watchers->isEmpty()) {
            return;
        }

        Notifications::send($watchers, $this->notification($event));
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
