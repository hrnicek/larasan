<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectMembership>
 */
class ProjectMembershipFactory extends Factory
{
    protected $model = ProjectMembership::class;

    /**
     * A project membership only means something inside the workspace that owns the project
     * (TASK-040-021), and the model refuses to create one that does not. The factory makes the
     * workspace membership the row needs rather than handing tests a shape the domain never
     * reaches — the rule the development seeder's docblock already states.
     */
    public function configure(): self
    {
        return $this->afterMaking(function (ProjectMembership $membership): void {
            $project = Project::query()->find($membership->project_id);

            if (! $project instanceof Project || $project->workspace->hasActiveMember($membership->user_id)) {
                return;
            }

            WorkspaceMembership::query()->updateOrCreate(
                ['workspace_id' => $project->workspace_id, 'user_id' => $membership->user_id],
                [
                    'role' => WorkspaceRole::Member,
                    'status' => WorkspaceMembershipStatus::Active,
                    'joined_at' => now(),
                    'expires_at' => null,
                ],
            );
        });
    }

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
