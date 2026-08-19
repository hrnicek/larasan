<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceMembership>
 */
class WorkspaceMembershipFactory extends Factory
{
    protected $model = WorkspaceMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'role' => WorkspaceRole::Member,
            'status' => WorkspaceMembershipStatus::Active,
            'joined_at' => now(),
            'expires_at' => null,
            'invited_by' => null,
        ];
    }

    public function owner(): self
    {
        return $this->state(fn (): array => ['role' => WorkspaceRole::Owner]);
    }

    public function admin(): self
    {
        return $this->state(fn (): array => ['role' => WorkspaceRole::Admin]);
    }

    public function member(): self
    {
        return $this->state(fn (): array => ['role' => WorkspaceRole::Member]);
    }

    public function guest(): self
    {
        return $this->state(fn (): array => ['role' => WorkspaceRole::Guest]);
    }

    public function active(): self
    {
        return $this->state(fn (): array => [
            'status' => WorkspaceMembershipStatus::Active,
            'joined_at' => now(),
        ]);
    }

    /**
     * An invitation as the domain produces one: not joined, and with a deadline.
     */
    public function invited(?User $invitedBy = null, ?CarbonImmutable $expiresAt = null): self
    {
        return $this->state(fn (): array => [
            'status' => WorkspaceMembershipStatus::Invited,
            'joined_at' => null,
            'expires_at' => $expiresAt ?? CarbonImmutable::now()->addWeek(),
            'invited_by' => $invitedBy?->id,
        ]);
    }

    public function withStatus(WorkspaceMembershipStatus $status): self
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'joined_at' => $status === WorkspaceMembershipStatus::Active ? now() : null,
        ]);
    }
}
