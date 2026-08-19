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
        $this->guard($workspace, $actor, $data);

        $membership = DB::transaction(function () use ($workspace, $actor, $data): WorkspaceMembership {
            /*
             * updateOrCreate rather than create: UNIQUE(workspace_id, user_id) means a
             * user who declined, was revoked or let an invitation lapse already has a row.
             * Inviting them again is a new invitation on that row, not a second membership
             * and not a constraint violation the caller has to interpret.
             */
            $membership = WorkspaceMembership::query()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'user_id' => $data->userId],
                [
                    'role' => $data->role,
                    'status' => WorkspaceMembershipStatus::Invited,
                    'joined_at' => null,
                    'expires_at' => $data->expiresAt(),
                    'invited_by' => $actor->id,
                ],
            );

            return $membership->refresh();
        });

        $membership->user->notify(new WorkspaceInvitationSent($membership->id));

        $this->events->dispatch(new WorkspaceMemberInvited(
            $membership->id,
            $workspace->id,
            $data->userId,
            $actor->id,
        ));

        return $membership;
    }

    private function guard(Workspace $workspace, User $actor, InviteWorkspaceMemberData $data): void
    {
        $actorMembership = $workspace->membershipFor($actor);

        if (! $actorMembership?->allows(Capability::WorkspaceMembersManage)) {
            throw WorkspaceMembershipException::roleRequiresCapability($data->role);
        }

        if ($data->role->isOwner()) {
            throw WorkspaceMembershipException::cannotAssignOwner();
        }

        $existing = $workspace->membershipFor(User::query()->findOrFail($data->userId));

        if ($existing?->status->grantsAccess() === true) {
            throw WorkspaceMembershipException::alreadyAMember();
        }

        /*
         * `updateOrCreate` rewrites the row, so inviting a *revoked owner* would demote
         * them permanently — Owner is never granted again — and strand the `owner_id`
         * holder. Same rule as removal and demotion: an owner's row takes an owner.
         */
        if ($existing?->role->isOwner() === true && ! $actorMembership->role->isOwner()) {
            throw WorkspaceMembershipException::onlyAnOwnerActsOnAnOwner();
        }

        /*
         * A live invitation is not re-sent. Without this the endpoint mails the same
         * address on every call — the throttle caps the rate, not the total.
         */
        if ($existing?->status === WorkspaceMembershipStatus::Invited && ! $existing->hasExpired()) {
            throw WorkspaceMembershipException::alreadyInvited();
        }
    }
}
