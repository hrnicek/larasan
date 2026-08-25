<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectStar;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectStar>
 */
class ProjectStarFactory extends Factory
{
    protected $model = ProjectStar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
        ];
    }

    public function starring(Project $project, User $user): self
    {
        return $this->state(fn (): array => [
            'project_id' => $project->id,
            'user_id' => $user->id,
        ]);
    }
}
