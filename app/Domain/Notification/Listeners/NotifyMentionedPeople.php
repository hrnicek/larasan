<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Comment\Events\CommentEdited;
use App\Domain\Comment\Models\Comment;
use App\Domain\Comment\Support\Mentions;
use App\Domain\Notification\Notifications\MentionedInCommentNotification;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification as Notifications;

final readonly class NotifyMentionedPeople implements ShouldQueue
{
    public function viaQueue(): string
    {
        return 'notifications';
    }

    public function handle(CommentCreated|CommentEdited $event): void
    {
        $authorId = $event instanceof CommentCreated ? $event->authorId : $event->editorId;

        $named = array_diff($event->mentionedIds, [$authorId]);

        if ($event->subjectType !== 'task' || $named === []) {
            return;
        }

        $comment = Comment::query()->find($event->commentId);
        $task = Task::query()->find($event->subjectId);

        if ($comment === null || $task === null) {
            return;
        }

        $stillNamed = array_intersect($named, Mentions::idsIn($comment->body));

        $recipients = User::query()->whereKey($stillNamed)->get()
            ->filter(fn (User $person): bool => $person->can('view', $task));

        if ($recipients->isEmpty()) {
            return;
        }

        Notifications::send($recipients, new MentionedInCommentNotification(
            $comment->id,
            $task->id,
            $comment->workspace_id,
            $authorId,
        ));
    }
}
