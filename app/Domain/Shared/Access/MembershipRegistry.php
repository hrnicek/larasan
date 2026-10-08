<?php

declare(strict_types=1);

namespace App\Domain\Shared\Access;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;

/**
 * Request-scoped memo, flushed by the membership model events registered in AppServiceProvider.
 * Query-builder mass updates fire no model events and must call flush() themselves.
 */
final class MembershipRegistry
{
    /** @var array<string, WorkspaceMembership|null> */
    private array $workspaces = [];

    /** @var array<string, ProjectMembership|null> */
    private array $projects = [];

    public function forWorkspace(Workspace $workspace, User $user): ?WorkspaceMembership
    {
        $key = $workspace->id.':'.$user->id;

        if (! array_key_exists($key, $this->workspaces)) {
            $this->workspaces[$key] = $workspace->memberships()->where('user_id', $user->id)->first();
        }

        return $this->workspaces[$key];
    }

    /**
     * @param  iterable<int, Project>  $projects
     */
    public function preloadProjects(iterable $projects, User $user): void
    {
        $missing = [];

        foreach ($projects as $project) {
            if (! array_key_exists($project->id.':'.$user->id, $this->projects)) {
                $missing[] = $project->id;
            }
        }

        if ($missing === []) {
            return;
        }

        $rows = ProjectMembership::query()
            ->whereIn('project_id', $missing)
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('project_id');

        foreach ($missing as $projectId) {
            $this->projects[$projectId.':'.$user->id] = $rows->get($projectId);
        }
    }

    public function forProject(Project $project, User $user): ?ProjectMembership
    {
        $key = $project->id.':'.$user->id;

        if (! array_key_exists($key, $this->projects)) {
            $this->projects[$key] = $project->memberships()->where('user_id', $user->id)->first();
        }

        return $this->projects[$key];
    }

    public function flush(): void
    {
        $this->workspaces = [];
        $this->projects = [];
    }
}
