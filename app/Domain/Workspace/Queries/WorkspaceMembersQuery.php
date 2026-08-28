<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Queries;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Http\Controllers\Workspace\WorkspaceMemberController;
use App\Models\User;

/**
 * Who is in this workspace, and who has been asked to be.
 *
 * Two lists rather than one, because they are two different sentences: the people here are the
 * workspace, and the invitations are a management matter — an address, a deadline and who sent
 * it. Declined and revoked rows are in neither: they are history, and bringing somebody back is
 * the invite form's job rather than a button on a row.
 *
 * @see WorkspaceMemberController
 */
final readonly class WorkspaceMembersQuery
{
    /**
     * @return array{members: list<array<string, mixed>>, invitations: list<array<string, mixed>>, can: array{manageMembers: bool}}
     */
    public function __invoke(Workspace $workspace, User $actor): array
    {
        $canManage = $actor->can(Capability::WorkspaceMembersManage->value, $workspace);

        return [
            'members' => $this->members($workspace, $actor, $canManage),
            'invitations' => $canManage ? $this->invitations($workspace) : [],
            'can' => [
                'manageMembers' => $canManage,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function members(Workspace $workspace, User $actor, bool $canManage): array
    {
        /*
         * Counted once rather than per row: `isLastOwner()` is a query, and the screen is open to
         * every member. It is also the only reason the list needs to know about owners at all.
         */
        $activeOwners = $workspace->memberships()
            ->where('role', WorkspaceRole::Owner->value)
            ->where('status', WorkspaceMembershipStatus::Active->value)
            ->count();

        $rows = $workspace->memberships()
            ->with('user')
            ->where('status', WorkspaceMembershipStatus::Active->value)
            ->orderBy('created_at')
            ->get()
            ->map(fn (WorkspaceMembership $membership): array => [
                'id' => $membership->id,
                // An active row always names an account — an unclaimed one can only be an
                // invitation — and the address is what is left if that ever stops being true.
                'name' => $membership->user->name ?? $membership->address(),
                /*
                 * A guest is an outside collaborator (ADR-0006); handing them every colleague's
                 * address is not part of commenting on a task. Managers need it to tell two
                 * people apart and to know who they invited.
                 */
                'email' => $canManage || $membership->user_id === $actor->id
                    ? $membership->address()
                    : null,
                'role' => $membership->role->value,
                'joinedAt' => $membership->joined_at?->toIso8601String(),
                'isYou' => $membership->user_id === $actor->id,
                'isLastOwner' => $membership->role->isOwner() && $activeOwners === 1,
            ]);

        return array_values($rows->all());
    }

    /**
     * Only for somebody who can act on them. An invitation is mostly an address, which this
     * screen deliberately withholds from everybody else.
     *
     * @return list<array<string, mixed>>
     */
    private function invitations(Workspace $workspace): array
    {
        $rows = $workspace->memberships()
            ->with('user', 'invitedBy')
            ->whereIn('status', [
                WorkspaceMembershipStatus::Invited->value,
                WorkspaceMembershipStatus::Expired->value,
            ])
            ->orderBy('created_at')
            ->get()
            ->map(fn (WorkspaceMembership $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->address(),
                'name' => $invitation->user->name ?? null,
                'role' => $invitation->role->value,
                'invitedBy' => $invitation->invitedBy->name ?? null,
                'expiresAt' => $invitation->expires_at?->toIso8601String(),
                // The sweep runs on a schedule, so a row can be past its deadline and still say
                // `invited`. The screen answers for the deadline, not the column.
                'hasExpired' => $invitation->hasExpired()
                    || $invitation->status === WorkspaceMembershipStatus::Expired,
                'hasAccount' => $invitation->isClaimed(),
            ]);

        return array_values($rows->all());
    }
}
