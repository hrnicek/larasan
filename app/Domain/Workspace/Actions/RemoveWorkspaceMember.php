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
     * An invitation nobody has claimed is deleted rather than revoked. There is no person
     * for the row to be a record of, and a revoked unclaimed row would go on occupying the
     * address in `workspace_memberships_workspace_id_email_unique` — so taking an
     * invitation back would quietly refuse the next one to the same address.
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

        $this->releaseTheirWork($workspace, $actor, $membership);

        $this->events->dispatch(new WorkspaceMemberRemoved(
            $membership->id,
            $workspace->id,
            $membership->user_id,
            $actor->id,
        ));

        return $membership;
    }

    /**
     * Work assigned to somebody who has been removed goes back to the project — on a queue.
     *
     * The alternative — leaving it assigned to a person who can no longer open it — makes work
     * nobody sees: it is in no list, and the only trace of it is a name on a card that leads
     * nowhere. Unassigning used to be the harder choice because it threw away the answer to
     * "who had this?"; since Phase 110 the activity table keeps that answer, so nothing is lost
     * (`docs/architecture/domains.md`).
     *
     * Through `AssignTask` rather than a mass update, so each task produces the same
     * `TaskAssigned` event any other unassignment does and the history reads as one thing — and
     * through a job rather than this request, because that is four queries per task and somebody
     * leaving may be holding hundreds (TASK-180-019). The revocation above is the security
     * answer and stays here; this is bookkeeping and can arrive a moment later.
     */
    private function releaseTheirWork(Workspace $workspace, User $actor, WorkspaceMembership $membership): void
    {
        $removed = $membership->user_id;

        // An invitation nobody ever claimed was never assigned anything.
        if ($removed === null) {
            return;
        }

        ReleaseRemovedMembersWork::dispatch($workspace->id, $removed, $actor->id);
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
