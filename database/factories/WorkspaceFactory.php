<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'owner_id' => User::factory(),
            'name' => $name,
            'slug' => Workspace::slugFor($name),
            'timezone' => 'UTC',
            'settings' => [],
        ];
    }

    public function ownedBy(User $user): self
    {
        return $this->state(fn (): array => ['owner_id' => $user->id]);
    }

    /**
     * The owner column alone makes nobody a member. Without this state a factory builds a
     * workspace its own owner cannot open — a shape the application never produces, and
     * one a test should have to ask for rather than get by default.
     */
    public function withOwnerMembership(): self
    {
        return $this->afterCreating(function (Workspace $workspace): void {
            WorkspaceMembership::factory()->owner()->active()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $workspace->owner_id,
            ]);
        });
    }
}
