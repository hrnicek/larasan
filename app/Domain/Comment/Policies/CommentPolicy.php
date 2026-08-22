<?php

declare(strict_types=1);

namespace App\Domain\Comment\Policies;

use App\Domain\Comment\Models\Comment;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A comment has no access rules of its own. Reading one is reading its subject, so the
 * question is handed to the subject's policy rather than answered again here — a second copy
 * of the reach rules would be the copy that goes out of date (TASK-070-017).
 *
 * What the comment does decide is who may change it. Editing is the author's alone: an
 * administrator who could rewrite what somebody else said would make the thread evidence of
 * nothing. Deleting is the author's or a moderator's, which is what `comment.delete` is for.
 *
 * Resolved by auto-discovery, and `CommentPolicyTest` asserts that resolution — the
 * `app/Domain/<Context>/Policies` pairing is not the layout the framework documents, so a
 * namespace move would otherwise stop authorizing in silence.
 */
class CommentPolicy
{
    public function view(User $user, Comment $comment): bool
    {
        return $this->canReachSubject($user, $comment);
    }

    /**
     * The author, and only while they can still reach what they were talking about. A deleted
     * comment is not edited back into existence either.
     */
    public function update(User $user, Comment $comment): bool
    {
        return $comment->author_id === $user->id
            && $comment->deleted_at === null
            && $this->canReachSubject($user, $comment);
    }

    public function delete(User $user, Comment $comment): bool
    {
        if ($comment->deleted_at !== null || ! $this->canReachSubject($user, $comment)) {
            return false;
        }

        return $comment->author_id === $user->id
            || $comment->workspace->membershipFor($user)?->allows(Capability::CommentDelete) === true;
    }

    /**
     * Membership is asked here rather than left to the subject: the subject's policy answers
     * for its own workspace, and a comment carries a `workspace_id` that must agree with it
     * before any of this means anything.
     */
    private function canReachSubject(User $user, Comment $comment): bool
    {
        $subject = $comment->commentable;

        if (! $subject instanceof Model) {
            return false;
        }

        return $comment->workspace->membershipFor($user)?->status->grantsAccess() === true
            && $user->can('view', $subject);
    }
}
