<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Actions;

use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Access\MembershipRegistry;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Events\WorkspaceMemberRemoved;
use App\Domain\Workspace\Exceptions\WorkspaceMembershipException;
use App\Domain\Workspace\Jobs\ReleaseRemovedMembersWork;
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

        // Owner can never be granted back, so only an owner may remove an owner.
        if ($membership->role->isOwner() && ! $actorMembership->role->isOwner()) {
            throw WorkspaceMembershipException::onlyAnOwnerActsOnAnOwner();
        }

        return DB::transaction(function () use ($workspace, $actor, $membership): WorkspaceMembership {
            if (! $membership->isClaimed()) {
                return $this->cancel($workspace, $actor, $membership);
            }

            if ($workspace->isLastOwner($membership, locking: true)) {
                throw WorkspaceMembershipException::lastOwner();
            }

            return $this->revoke($workspace, $actor, $membership);
        });
    }

    /**
     * Deleted rather than revoked, since a revoked unclaimed row would keep blocking the address
     * in workspace_memberships_workspace_id_email_unique.
     */
    private function cancel(Workspace $workspace, User $actor, WorkspaceMembership $membership): WorkspaceMembership
    {
        $membership->delete();

        $this->events->dispatch(new WorkspaceMemberRemoved(
            $membership->id,
            $workspace->id,
            null,
            $actor->id,
        ));

        return $membership;
    }

    private function revoke(Workspace $workspace, User $actor, WorkspaceMembership $membership): WorkspaceMembership
    {
        // Revoked, not deleted, so the activity trail survives and a re-invitation reuses the row.
        $this->revokeProjectAccess($workspace, $membership);

        $membership->forceFill([
            'status' => WorkspaceMembershipStatus::Revoked,
            'expires_at' => null,
        ])->save();

        $this->releaseTheirWork($workspace, $actor, $membership);

        $this->events->dispatch(new WorkspaceMemberRemoved(
            $membership->id,
            $workspace->id,
            $membership->user_id,
            $actor->id,
        ));

        return $membership;
    }

    private function releaseTheirWork(Workspace $workspace, User $actor, WorkspaceMembership $membership): void
    {
        $removed = $membership->user_id;

        if ($removed === null) {
            return;
        }

        ReleaseRemovedMembersWork::dispatch($workspace->id, $removed, $actor->id);
    }

    /**
     * Otherwise a re-invitation would silently restore access to every project they were in.
     */
    private function revokeProjectAccess(Workspace $workspace, WorkspaceMembership $membership): void
    {
        ProjectMembership::query()
            ->whereIn('project_id', $workspace->projects()->withTrashed()->select('projects.id'))
            ->where('user_id', $membership->user_id)
            ->delete();

        // A mass delete fires no model events, so the registry must be flushed by hand.
        app(MembershipRegistry::class)->flush();
    }
}
