<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Search\Models\SavedSearch;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedSearch>
 */
class SavedSearchFactory extends Factory
{
    protected $model = SavedSearch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'name' => $this->faker->unique()->words(2, true),
            'term' => $this->faker->word(),
            'kind' => null,
            'filters' => [],
        ];
    }

    public function ownedBy(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }

    public function in(Workspace $workspace): self
    {
        return $this->state(fn (): array => ['workspace_id' => $workspace->id]);
    }
}
