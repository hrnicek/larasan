<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Events\WorkspaceMemberInvited;
use App\Domain\Workspace\Exceptions\WorkspaceMembershipException;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Domain\Workspace\Notifications\WorkspaceInvitationSent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Sending an invitation again — because it lapsed, or because the first mail went unread.
 *
 * Separate from `InviteWorkspaceMember`, which refuses a live invitation on purpose: without
 * that refusal the invite form mails the same address on every submission. Saying *send it
 * again* is a decision, and this is where it is made.
 */
final readonly class ResendWorkspaceInvitation
{
    public function __construct(private Dispatcher $events) {}

    public function handle(
        Workspace $workspace,
        User $actor,
        WorkspaceMembership $membership,
        ?CarbonImmutable $expiresAt = null,
    ): WorkspaceMembership {
        $this->guard($workspace, $actor, $membership);

        $membership->forceFill([
            'status' => WorkspaceMembershipStatus::Invited,
            'joined_at' => null,
            'expires_at' => $expiresAt ?? CarbonImmutable::now()->addWeek(),
            /*
             * The resender becomes the inviter. Acceptance asks whether `invited_by` still may
             * invite, so an invitation whose original sender has since been removed would be
             * dead on arrival — and re-sending it is precisely somebody with the capability
             * saying it stands.
             */
            'invited_by' => $actor->id,
        ])->save();

        $membership->invitee()->notify(new WorkspaceInvitationSent($membership->id));

        $this->events->dispatch(new WorkspaceMemberInvited(
            $membership->id,
            $workspace->id,
            $membership->user_id,
            $membership->address(),
            $actor->id,
        ));

        return $membership;
    }

    private function guard(Workspace $workspace, User $actor, WorkspaceMembership $membership): void
    {
        $actorMembership = $workspace->membershipFor($actor);

        if (! $actorMembership?->allows(Capability::WorkspaceMembersManage)) {
            throw WorkspaceMembershipException::roleRequiresCapability($membership->role);
        }

        if ($membership->status->grantsAccess()) {
            throw WorkspaceMembershipException::alreadyAMember();
        }

        /*
         * Only what is still an invitation. A declined or revoked row is a decision somebody
         * made, and overturning it is inviting them again — which the invite form does, and
         * which says so on the screen.
         */
        if (! in_array($membership->status, [WorkspaceMembershipStatus::Invited, WorkspaceMembershipStatus::Expired], true)) {
            throw WorkspaceMembershipException::invitationNotPending($membership->status);
        }

        if ($membership->role->isOwner() && ! $actorMembership->role->isOwner()) {
            throw WorkspaceMembershipException::onlyAnOwnerActsOnAnOwner();
        }
    }
}
