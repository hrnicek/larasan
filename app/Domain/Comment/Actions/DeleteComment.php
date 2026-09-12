<?php

declare(strict_types=1);

namespace App\Domain\Comment\Actions;

use App\Domain\Comment\Events\CommentDeleted;
use App\Domain\Comment\Exceptions\CommentException;
use App\Domain\Comment\Models\Comment;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

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
