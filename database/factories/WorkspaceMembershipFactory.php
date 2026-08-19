<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
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

    public function withStatus(WorkspaceMembershipStatus $status): self
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'joined_at' => $status === WorkspaceMembershipStatus::Active ? now() : null,
        ]);
    }
}
