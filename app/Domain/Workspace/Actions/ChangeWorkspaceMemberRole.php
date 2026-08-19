<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Events\WorkspaceMemberRoleChanged;
use App\Domain\Workspace\Exceptions\WorkspaceMembershipException;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

final readonly class ChangeWorkspaceMemberRole
{
    public function __construct(private Dispatcher $events) {}

    public function handle(
        Workspace $workspace,
        User $actor,
        WorkspaceMembership $membership,
        WorkspaceRole $role,
    ): WorkspaceMembership {
        $this->guard($workspace, $actor, $membership, $role);

        $from = $membership->role;

        if ($from === $role) {
            return $membership;
        }

        return DB::transaction(function () use ($workspace, $membership, $role, $from): WorkspaceMembership {
            /*
             * Inside the transaction and locking: the check and the write have to be one
             * step, or two requests demoting the two remaining owners both pass.
             */
            if ($workspace->isLastOwner($membership, locking: true)) {
                throw WorkspaceMembershipException::lastOwner();
            }

            $membership->forceFill(['role' => $role])->save();

            $this->events->dispatch(new WorkspaceMemberRoleChanged(
                $membership->id,
                $workspace->id,
                $membership->user_id,
                $from,
                $role,
            ));

            return $membership;
        });
    }

    private function guard(
        Workspace $workspace,
        User $actor,
        WorkspaceMembership $membership,
        WorkspaceRole $role,
    ): void {
        $actorMembership = $workspace->membershipFor($actor);

        if (! $actorMembership?->allows(Capability::WorkspaceMembersManage)) {
            throw WorkspaceMembershipException::roleRequiresCapability($role);
        }

        /*
         * An admin may manage members, but demoting an owner is permanent — no code path
         * grants Owner back — so it takes an owner to do it.
         */
        if ($membership->role->isOwner() && ! $actorMembership->role->isOwner()) {
            throw WorkspaceMembershipException::onlyAnOwnerActsOnAnOwner();
        }

        /*
         * An admin who could promote themselves to owner would make the capability model
         * decorative, and the same check stops an owner demoting themselves out of the
         * last-owner rule.
         */
        if ($membership->user_id === $actor->id) {
            throw WorkspaceMembershipException::cannotChangeOwnRole();
        }

        if ($role->isOwner()) {
            throw WorkspaceMembershipException::cannotAssignOwner();
        }

    }
}
