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

/**
 * Tell the people watching a task that somebody said something about it.
 *
 * Watching is following it or being the person it was given to — an assignee who was never
 * asked to follow their own work would otherwise hear nothing about it. Each person is told
 * once however many of those they are.
 *
 * Reach is checked on the way out, which is the other half of the rule `FollowTask` enforces on
 * the way in: somebody can follow a task and then lose the project it lives in, and an inbox
 * full of work nobody can open is worse than no notification at all (TASK-070-017).
 *
 * Only tasks for now: a comment can hang from anything, but nothing else has followers yet, and
 * a listener that guessed at the others would be guessing about who should hear from them.
 */
final readonly class NotifyWatchersOfComment implements ShouldQueue
{
    /**
     * The `notifications` queue Horizon already supervises, below `broadcasts` (ADR-0008): a
     * slow inbox must never delay a board update, and nobody's request should wait on somebody
     * else's notification.
     *
     * The connection is left to configuration. Pinning it here would send the tests' jobs to a
     * Redis nobody asked them to need.
     */
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
            // Never the author. Being told about your own comment is the fastest way to teach
            // somebody to ignore the inbox entirely.
            ->reject(fn (User $watcher): bool => $watcher->id === $event->authorId)
            // Somebody the comment names hears about it once, as the mention.
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
