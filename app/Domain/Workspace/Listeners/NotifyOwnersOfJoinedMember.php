<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Listeners;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Events\WorkspaceInvitationAnswered;
use App\Domain\Workspace\Notifications\WorkspaceMemberJoined;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

/**
 * Not queued: the notification queues one mail per owner, so a failed send never repeats the others.
 */
final readonly class NotifyOwnersOfJoinedMember
{
    public function handle(WorkspaceInvitationAnswered $event): void
    {
        if ($event->answer !== WorkspaceMembershipStatus::Active) {
            return;
        }

        $owners = User::query()
            ->whereKeyNot($event->userId)
            ->whereHas('workspaceMemberships', fn (Builder $memberships): Builder => $memberships
                ->where('workspace_id', $event->workspaceId)
                ->where('role', WorkspaceRole::Owner->value)
                ->where('status', WorkspaceMembershipStatus::Active->value))
            ->get();

        Notification::send($owners, new WorkspaceMemberJoined($event->membershipId));
    }
}
