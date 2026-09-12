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
            // Acceptance re-checks the inviter's authority, so the resender becomes the inviter.
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

        if (! in_array($membership->status, [WorkspaceMembershipStatus::Invited, WorkspaceMembershipStatus::Expired], true)) {
            throw WorkspaceMembershipException::invitationNotPending($membership->status);
        }

        if ($membership->role->isOwner() && ! $actorMembership->role->isOwner()) {
            throw WorkspaceMembershipException::onlyAnOwnerActsOnAnOwner();
        }
    }
}
