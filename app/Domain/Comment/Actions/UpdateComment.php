<?php

declare(strict_types=1);

namespace App\Domain\Comment\Actions;

use App\Domain\Comment\Data\UpdateCommentData;
use App\Domain\Comment\Events\CommentEdited;
use App\Domain\Comment\Exceptions\CommentException;
use App\Domain\Comment\Models\Comment;
use App\Domain\Comment\Models\Commentable;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class UpdateComment
{
    public function __construct(
        private Dispatcher $events,
        private ResolveMentions $mentions,
    ) {}

    public function handle(Comment $comment, User $actor, UpdateCommentData $data): Comment
    {
        if ($actor->cannot('update', $comment)) {
            throw CommentException::notTheAuthor();
        }

        $body = trim($data->body);

        if ($body === '') {
            throw CommentException::bodyIsEmpty();
        }

        if ($body === $comment->body) {
            return $comment;
        }

        $subject = $comment->commentable;

        if (! $subject instanceof Commentable) {
            throw CommentException::cannotReachSubject();
        }

        $mentioned = $this->mentions->handle($subject, $body);

        // Compared again after names are rewritten, so a stale mention name alone is not an edit.
        if ($mentioned->body === $comment->body) {
            return $comment;
        }

        $comment->body = $mentioned->body;
        $comment->edited_at = now()->toImmutable();
        $comment->save();

        $this->events->dispatch(new CommentEdited(
            $comment->id,
            $comment->workspace_id,
            $comment->commentable_type,
            $comment->commentable_id,
            $actor->id,
            $mentioned->mentionedIds,
        ));

        return $comment;
    }
}
