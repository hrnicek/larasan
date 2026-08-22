<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Actions;

use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Access\MembershipRegistry;
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
        $this->revokeProjectAccess($workspace, $membership);

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

    /**
     * The workspace membership is the ground the project grants stand on, so removing
     * somebody takes their project access with it, in the same transaction. Leaving the rows
     * behind would mean a re-invitation silently restored access to every project they were
     * ever in — and until then, a revoked membership with live `project_memberships` rows is
     * a grant nothing enforces and every future reader has to remember to ignore.
     *
     * Deleted, unlike the workspace membership itself: a project membership records access
     * rather than history, and the workspace membership is where the record of the person
     * being here lives.
     */
    private function revokeProjectAccess(Workspace $workspace, WorkspaceMembership $membership): void
    {
        ProjectMembership::query()
            ->whereIn('project_id', $workspace->projects()->withTrashed()->select('projects.id'))
            ->where('user_id', $membership->user_id)
            ->delete();

        // A mass delete fires no model events, so the request-scoped memo cannot notice it.
        app(MembershipRegistry::class)->flush();
    }
}
