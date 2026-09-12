<?php

declare(strict_types=1);

namespace App\Domain\Comment\Actions;

use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Comment\Exceptions\CommentException;
use App\Domain\Comment\Models\Comment;
use App\Domain\Comment\Models\Commentable;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final readonly class CreateComment
{
    public function __construct(
        private Dispatcher $events,
        private ResolveMentions $mentions,
    ) {}

    public function handle(Model&Commentable $subject, User $actor, CreateCommentData $data): Comment
    {
        $workspace = Workspace::query()->findOrFail($subject->workspaceId());

        if (! $workspace->membershipFor($actor)?->allows(Capability::CommentCreate)) {
            throw CommentException::cannotComment();
        }

        if ($actor->cannot('view', $subject)) {
            throw CommentException::cannotReachSubject();
        }

        if ($actor->cannot('comment', $subject)) {
            throw CommentException::cannotComment();
        }

        $body = trim($data->body);

        if ($body === '') {
            throw CommentException::bodyIsEmpty();
        }

        $mentioned = $this->mentions->handle($subject, $body);

        $comment = new Comment(['body' => $mentioned->body]);

        // The subject's workspace, never the request's, so the row cannot leak across tenants.
        $comment->workspace_id = $subject->workspaceId();
        $comment->commentable_type = (string) Relation::getMorphAlias($subject::class);
        $comment->commentable_id = (string) $subject->getKey();
        $comment->author_id = $actor->id;
        $comment->save();

        $this->events->dispatch(new CommentCreated(
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
