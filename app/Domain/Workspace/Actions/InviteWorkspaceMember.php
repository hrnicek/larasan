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
            /*
             * The row is reused rather than added to. UNIQUE(workspace_id, user_id) means a
             * user who declined, was revoked or let an invitation lapse already has one, and
             * an address invited before it had an account has one keyed by the address —
             * inviting either again is a new invitation on that row, not a second membership.
             */
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

    /**
     * The row this invitation would land on: the account's, or the one the address holds
     * while nobody has registered under it.
     */
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

        /*
         * The row is rewritten, so inviting a *revoked owner* would demote them permanently
         * — Owner is never granted again — and strand the `owner_id` holder. Same rule as
         * removal and demotion: an owner's row takes an owner.
         */
        if ($existing?->role->isOwner() === true && ! $actorMembership->role->isOwner()) {
            throw WorkspaceMembershipException::onlyAnOwnerActsOnAnOwner();
        }

        /*
         * A live invitation is not re-sent by inviting again. Without this the endpoint
         * mails the same address on every call — the throttle caps the rate, not the total.
         * Sending it again on purpose is `ResendWorkspaceInvitation`.
         */
        if ($existing?->status === WorkspaceMembershipStatus::Invited && ! $existing->hasExpired()) {
            throw WorkspaceMembershipException::alreadyInvited();
        }
    }
}
