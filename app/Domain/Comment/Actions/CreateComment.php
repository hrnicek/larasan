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

/**
 * Say something about a task.
 *
 * Two questions, both required: the workspace capability says the actor may comment at all,
 * and the subject's own policy says they may reach the thing they are commenting on
 * (TASK-070-017's rule, applied to writing rather than reading). Neither alone is enough — a
 * capability without reach would let somebody comment into a private project they were never
 * given, which is a way of talking to people who cannot hear you and reading what they say
 * back.
 */
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

        $body = trim($data->body);

        // Whitespace is not a comment. Refused here rather than only in the request, because a
        // console command and a queued job never pass one.
        if ($body === '') {
            throw CommentException::bodyIsEmpty();
        }

        $mentioned = $this->mentions->handle($subject, $body);

        $comment = new Comment(['body' => $mentioned->body]);

        /*
         * The **subject's** workspace, not the request's. A comment scoped to whichever
         * workspace the actor happened to be in would be a row that leaks across tenants the
         * first time somebody follows a link from somewhere else.
         */
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
