<?php

declare(strict_types=1);

namespace App\Domain\Comment\Actions;

use App\Domain\Comment\Data\UpdateCommentData;
use App\Domain\Comment\Events\CommentEdited;
use App\Domain\Comment\Exceptions\CommentException;
use App\Domain\Comment\Models\Comment;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Change what was said, and say that it changed.
 *
 * `edited_at` is the whole point. A thread that silently presents different words is worse
 * than one that cannot be edited at all: everybody who replied is then answering something
 * nobody can see any more.
 */
final readonly class UpdateComment
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Comment $comment, User $actor, UpdateCommentData $data): Comment
    {
        if ($actor->cannot('update', $comment)) {
            throw CommentException::notTheAuthor();
        }

        $body = trim($data->body);

        if ($body === '') {
            throw CommentException::bodyIsEmpty();
        }

        // Re-saving the same words is not an edit, and marking one would tell the thread that
        // something changed when nothing did.
        if ($body === $comment->body) {
            return $comment;
        }

        $comment->body = $body;
        $comment->edited_at = now()->toImmutable();
        $comment->save();

        $this->events->dispatch(new CommentEdited(
            $comment->id,
            $comment->workspace_id,
            $comment->commentable_type,
            $comment->commentable_id,
            $actor->id,
        ));

        return $comment;
    }
}
