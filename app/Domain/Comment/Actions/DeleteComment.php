<?php

declare(strict_types=1);

namespace App\Domain\Comment\Actions;

use App\Domain\Comment\Events\CommentDeleted;
use App\Domain\Comment\Exceptions\CommentException;
use App\Domain\Comment\Models\Comment;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Take a comment out of the conversation without taking it out of the record.
 *
 * The row stays, so the thread can say that something was removed rather than closing the gap
 * and presenting a conversation that reads differently from the one people had. Who may do it
 * is the policy's answer: the author, or anybody the workspace trusts to moderate.
 */
final readonly class DeleteComment
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Comment $comment, User $actor): void
    {
        if ($actor->cannot('delete', $comment)) {
            throw CommentException::cannotDeleteComment();
        }

        $comment->delete();

        $this->events->dispatch(new CommentDeleted(
            $comment->id,
            $comment->workspace_id,
            $comment->commentable_type,
            $comment->commentable_id,
            $actor->id,
        ));
    }
}
