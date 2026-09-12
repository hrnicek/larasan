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
                'name' => $membership->user->name ?? $membership->address(),
                // Addresses are withheld from non-managers such as guests. See ADR-0006.
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
                'hasExpired' => $invitation->hasExpired()
                    || $invitation->status === WorkspaceMembershipStatus::Expired,
                'hasAccount' => $invitation->isClaimed(),
            ]);

        return array_values($rows->all());
    }
}
