<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectMembership>
 */
class ProjectMembershipFactory extends Factory
{
    protected $model = ProjectMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'access_level' => ProjectAccessLevel::Editor,
        ];
    }

    public function in(Project $project): self
    {
        return $this->state(fn (): array => ['project_id' => $project->id]);
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }

    public function withAccess(ProjectAccessLevel $level): self
    {
        return $this->state(fn (): array => ['access_level' => $level]);
    }
}
