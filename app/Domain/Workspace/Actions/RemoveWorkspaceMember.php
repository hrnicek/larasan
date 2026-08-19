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

final readonly class RemoveWorkspaceMember
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Workspace $workspace, User $actor, WorkspaceMembership $membership): WorkspaceMembership
    {
        if (! $workspace->membershipFor($actor)?->allows(Capability::WorkspaceMembersManage)) {
            throw WorkspaceMembershipException::roleRequiresCapability($membership->role);
        }

        if ($workspace->isLastOwner($membership)) {
            throw WorkspaceMembershipException::lastOwner();
        }

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
