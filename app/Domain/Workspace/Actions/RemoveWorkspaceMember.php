<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Events\WorkspaceMemberRemoved;
use App\Domain\Workspace\Exceptions\WorkspaceMembershipException;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

final readonly class RemoveWorkspaceMember
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Workspace $workspace, User $actor, WorkspaceMembership $membership): WorkspaceMembership
    {
        $actorMembership = $workspace->membershipFor($actor);

        if (! $actorMembership?->allows(Capability::WorkspaceMembersManage)) {
            throw WorkspaceMembershipException::roleRequiresCapability($membership->role);
        }

        /*
         * An admin holds workspace.members.manage, but an owner is not theirs to remove:
         * ownership cannot be granted back by any code path, so this would be permanent
         * and would also strand the `owner_id` holder, whose account cannot be deleted
         * while they own a workspace.
         */
        if ($membership->role->isOwner() && ! $actorMembership->role->isOwner()) {
            throw WorkspaceMembershipException::onlyAnOwnerActsOnAnOwner();
        }

        return DB::transaction(function () use ($workspace, $actor, $membership): WorkspaceMembership {
            if ($workspace->isLastOwner($membership, locking: true)) {
                throw WorkspaceMembershipException::lastOwner();
            }

            return $this->revoke($workspace, $actor, $membership);
        });
    }

    private function revoke(Workspace $workspace, User $actor, WorkspaceMembership $membership): WorkspaceMembership
    {
        /*
         * Revoked, not deleted. The row is the record that this person was here, the
         * unique index means a re-invitation reuses it (TASK-030-004), and a deleted row
         * would take the activity trail with it. `joined_at` stays: it is when they
         * joined, which remains true.
         */
        $membership->forceFill([
            'status' => WorkspaceMembershipStatus::Revoked,
            'expires_at' => null,
        ])->save();

        $this->events->dispatch(new WorkspaceMemberRemoved(
            $membership->id,
            $workspace->id,
            $membership->user_id,
            $actor->id,
        ));

        return $membership;
    }
}
