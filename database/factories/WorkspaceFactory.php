<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Workspace\Models\Workspace;
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
}
