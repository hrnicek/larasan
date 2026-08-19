<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Actions;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Events\WorkspaceInvitationAnswered;
use App\Domain\Workspace\Exceptions\WorkspaceMembershipException;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class AnswerWorkspaceInvitation
{
    public function __construct(private Dispatcher $events) {}

    public function accept(WorkspaceMembership $membership, User $actor): WorkspaceMembership
    {
        $this->guard($membership, $actor);

        /*
         * Expiry is checked on acceptance only. Declining an invitation that lapsed is
         * harmless and refusing it would leave the row pending until a sweep runs.
         */
        if ($membership->hasExpired()) {
            throw WorkspaceMembershipException::invitationExpired();
        }

        return $this->answer($membership, WorkspaceMembershipStatus::Active);
    }

    public function decline(WorkspaceMembership $membership, User $actor): WorkspaceMembership
    {
        $this->guard($membership, $actor);

        return $this->answer($membership, WorkspaceMembershipStatus::Declined);
    }

    private function answer(WorkspaceMembership $membership, WorkspaceMembershipStatus $answer): WorkspaceMembership
    {
        $membership->forceFill([
            'status' => $answer,
            'joined_at' => $answer->grantsAccess() ? now() : null,
            // The deadline belonged to the invitation, and the invitation is over.
            'expires_at' => null,
        ])->save();

        $this->events->dispatch(new WorkspaceInvitationAnswered(
            $membership->id,
            $membership->workspace_id,
            $membership->user_id,
            $answer,
        ));

        return $membership;
    }

    private function guard(WorkspaceMembership $membership, User $actor): void
    {
        if ($membership->user_id !== $actor->id) {
            throw WorkspaceMembershipException::notTheInvitee();
        }

        if (! $membership->status->canBeAccepted()) {
            throw WorkspaceMembershipException::invitationNotPending($membership->status);
        }
    }
}
