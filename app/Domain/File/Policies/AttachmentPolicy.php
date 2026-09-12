<?php

declare(strict_types=1);

namespace App\Domain\File\Policies;

use App\Domain\File\Models\Attachment;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Auto-discovered from a non-standard layout; AttachmentPolicyTest guards that resolution. */
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

    public function move(User $user, Attachment $attachment): bool
    {
        $subject = $attachment->attachable;

        return $this->canReachSubject($user, $attachment)
            && $subject instanceof Model
            && $user->can('update', $subject);
    }

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
