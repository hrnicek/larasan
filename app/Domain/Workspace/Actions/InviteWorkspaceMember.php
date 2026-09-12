<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Data\InviteWorkspaceMemberData;
use App\Domain\Workspace\Events\WorkspaceMemberInvited;
use App\Domain\Workspace\Exceptions\WorkspaceMembershipException;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Domain\Workspace\Notifications\WorkspaceInvitationSent;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

final readonly class InviteWorkspaceMember
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Workspace $workspace, User $actor, InviteWorkspaceMemberData $data): WorkspaceMembership
    {
        $invitee = User::query()->where('email', $data->email)->first();
        $existing = $this->existing($workspace, $data->email, $invitee);

        $this->guard($workspace, $actor, $data, $existing);

        $membership = DB::transaction(function () use ($workspace, $actor, $data, $invitee, $existing): WorkspaceMembership {
            // Reuses the existing row: memberships are unique per user and per unclaimed address.
            $membership = $existing ?? new WorkspaceMembership;

            $membership->forceFill([
                'workspace_id' => $workspace->id,
                'user_id' => $invitee instanceof User ? $invitee->id : $membership->user_id,
                'email' => $data->email,
                'role' => $data->role,
                'status' => WorkspaceMembershipStatus::Invited,
                'joined_at' => null,
                'expires_at' => $data->expiresAt(),
                'invited_by' => $actor->id,
            ])->save();

            return $membership->refresh();
        });

        $membership->invitee()->notify(new WorkspaceInvitationSent($membership->id));

        $this->events->dispatch(new WorkspaceMemberInvited(
            $membership->id,
            $workspace->id,
            $membership->user_id,
            $membership->email ?? $data->email,
            $actor->id,
        ));

        return $membership;
    }

    private function existing(Workspace $workspace, string $email, ?User $invitee): ?WorkspaceMembership
    {
        if ($invitee instanceof User) {
            $claimed = $workspace->membershipFor($invitee);

            if ($claimed instanceof WorkspaceMembership) {
                return $claimed;
            }
        }

        return $workspace->memberships()->whereNull('user_id')->where('email', $email)->first();
    }

    private function guard(
        Workspace $workspace,
        User $actor,
        InviteWorkspaceMemberData $data,
        ?WorkspaceMembership $existing,
    ): void {
        $actorMembership = $workspace->membershipFor($actor);

        if (! $actorMembership?->allows(Capability::WorkspaceMembersManage)) {
            throw WorkspaceMembershipException::roleRequiresCapability($data->role);
        }

        if ($data->role->isOwner()) {
            throw WorkspaceMembershipException::cannotAssignOwner();
        }

        if ($existing?->status->grantsAccess() === true) {
            throw WorkspaceMembershipException::alreadyAMember();
        }

        // Re-inviting rewrites the role, which would permanently demote a revoked owner.
        if ($existing?->role->isOwner() === true && ! $actorMembership->role->isOwner()) {
            throw WorkspaceMembershipException::onlyAnOwnerActsOnAnOwner();
        }

        // Otherwise every submission would mail the address again; see ResendWorkspaceInvitation.
        if ($existing?->status === WorkspaceMembershipStatus::Invited && ! $existing->hasExpired()) {
            throw WorkspaceMembershipException::alreadyInvited();
        }
    }
}
