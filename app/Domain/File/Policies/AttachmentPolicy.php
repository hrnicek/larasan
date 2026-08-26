<?php

declare(strict_types=1);

namespace App\Domain\File\Policies;

use App\Domain\File\Models\Attachment;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * An attachment has no access rules of its own: seeing one is seeing the thing it hangs from,
 * so the question is handed to that subject's policy rather than answered twice.
 *
 * Removing one is the uploader's, or a holder of `file.delete` — the same shape as a comment,
 * where the author may remove what they added and the workspace can moderate what anybody did.
 *
 * Resolved by auto-discovery, and `AttachmentPolicyTest` asserts that resolution: the
 * `app/Domain/<Context>/Policies` pairing is not the layout the framework documents, so a
 * namespace move would otherwise stop authorizing in silence.
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $this->canReachSubject($user, $attachment);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        if (! $this->canReachSubject($user, $attachment)) {
            return false;
        }

        $file = $attachment->file;

        return $file->uploaded_by === $user->id
            || $file->workspace->membershipFor($user)?->allows(Capability::FileDelete) === true;
    }

    /**
     * Rearranging a task's files is changing the task, so the subject's own `update` answers it.
     * Deliberately not the uploader's own right: order is a property of the list, not of any one
     * file in it, and a viewer who may open a task does not get to rearrange what it holds.
     */
    public function move(User $user, Attachment $attachment): bool
    {
        $subject = $attachment->attachable;

        return $this->canReachSubject($user, $attachment)
            && $subject instanceof Model
            && $user->can('update', $subject);
    }

    /**
     * The workspace is asked here rather than left to the subject: the subject answers for its
     * own workspace, and a file carries a `workspace_id` that has to agree with it before any
     * of this means anything.
     */
    private function canReachSubject(User $user, Attachment $attachment): bool
    {
        $subject = $attachment->attachable;

        if (! $subject instanceof Model) {
            return false;
        }

        return $attachment->file->workspace->membershipFor($user)?->status->grantsAccess() === true
            && $user->can('view', $subject);
    }
}
