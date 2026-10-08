<?php

declare(strict_types=1);

namespace App\Domain\Comment\Policies;

use App\Domain\Comment\Models\Comment;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Auto-discovered from a non-standard layout; CommentPolicyTest guards that resolution. */
class CommentPolicy
{
    public function view(User $user, Comment $comment): bool
    {
        return $this->canReachSubject($user, $comment);
    }

    public function update(User $user, Comment $comment): bool
    {
        return $comment->author_id === $user->id
            && $comment->deleted_at === null
            && $this->canReachSubject($user, $comment)
            && $user->can('comment', $comment->commentable);
    }

    public function delete(User $user, Comment $comment): bool
    {
        if ($comment->deleted_at !== null || ! $this->canReachSubject($user, $comment)) {
            return false;
        }

        return $comment->author_id === $user->id
            || $comment->workspace->membershipFor($user)?->allows(Capability::CommentDelete) === true;
    }

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
