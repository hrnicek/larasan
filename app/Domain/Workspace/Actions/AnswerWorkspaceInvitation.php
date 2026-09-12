<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Actions;

use App\Domain\Shared\Enums\Capability;
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

        // Only acceptance checks expiry; declining a lapsed invitation is harmless.
        if ($membership->hasExpired()) {
            throw WorkspaceMembershipException::invitationExpired();
        }

        if (! $this->inviterStillMayInvite($membership)) {
            throw WorkspaceMembershipException::inviterNoLongerMayInvite();
        }

        return $this->answer($membership, $actor, WorkspaceMembershipStatus::Active);
    }

    public function decline(WorkspaceMembership $membership, User $actor): WorkspaceMembership
    {
        $this->guard($membership, $actor);

        return $this->answer($membership, $actor, WorkspaceMembershipStatus::Declined);
    }

    private function answer(WorkspaceMembership $membership, User $actor, WorkspaceMembershipStatus $answer): WorkspaceMembership
    {
        $membership->forceFill([
            'status' => $answer,
            'joined_at' => $answer->grantsAccess() ? now() : null,
            'expires_at' => null,
        ])->save();

        $this->events->dispatch(new WorkspaceInvitationAnswered(
            $membership->id,
            $membership->workspace_id,
            $actor->id,
            $answer,
        ));

        return $membership;
    }

    private function guard(WorkspaceMembership $membership, User $actor): void
    {
        if ($membership->user_id !== $actor->id || ! $this->holdsInvitedAddress($membership, $actor)) {
            throw WorkspaceMembershipException::notTheInvitee();
        }

        if (! $membership->status->canBeAccepted()) {
            throw WorkspaceMembershipException::invitationNotPending($membership->status);
        }
    }

    /**
     * A claimed row alone is not proof: the account may have changed its address since claiming.
     */
    private function holdsInvitedAddress(WorkspaceMembership $membership, User $actor): bool
    {
        return $actor->hasVerifiedEmail()
            && mb_strtolower($actor->email) === mb_strtolower($membership->address());
    }

    /**
     * Stops a removed admin's pending invitation from being accepted as a back door.
     */
    private function inviterStillMayInvite(WorkspaceMembership $membership): bool
    {
        // invited_by is null-on-delete; a creator's null inviter never appears on an Invited row.
        if ($membership->invited_by === null) {
            return false;
        }

        $inviter = $membership->invitedBy;

        return $inviter !== null
            && $membership->workspace->membershipFor($inviter)?->allows(Capability::WorkspaceMembersManage) === true;
    }
}
