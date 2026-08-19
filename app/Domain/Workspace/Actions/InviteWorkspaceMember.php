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
        if (! $workspace->membershipFor($actor)?->allows(Capability::WorkspaceMembersManage)) {
            throw WorkspaceMembershipException::roleRequiresCapability($data->role);
        }

        if ($data->role->isOwner()) {
            throw WorkspaceMembershipException::cannotInviteAsOwner();
        }

        if ($workspace->membershipFor(User::query()->findOrFail($data->userId))?->status->grantsAccess() === true) {
            throw WorkspaceMembershipException::alreadyAMember();
        }
    }
}
