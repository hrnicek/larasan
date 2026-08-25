<?php

declare(strict_types=1);

namespace App\Domain\Shared\Access;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;

/**
 * One membership lookup per actor per request, rather than one per ability.
 *
 * Authorization is asked many times in a single request — a policy, a controller, the
 * shared Inertia props, a view rendering what the actor may do — and each ask read the same
 * two rows again. A project settings page ran sixteen `workspace_memberships` queries and
 * nine on `project_memberships` before this existed.
 *
 * The memo is request-scoped, never longer. Anything longer would be an authorization answer
 * outliving the row it came from, which is the failure mode of every "clever" permission
 * cache: a role changed in one request and honoured in the next.
 *
 * It is also emptied whenever a membership row is written or deleted, through the model
 * events registered in `AppServiceProvider`. That is what makes it safe inside an Action
 * that reads a membership again after changing it — the case an earlier attempt at this got
 * wrong by caching on the model instance.
 *
 * The one write it cannot see is a mass update through the query builder, which fires no
 * model events. `ExpireWorkspaceInvitations` is that write, and it runs on the schedule in
 * its own process; it flushes this anyway rather than relying on that staying true.
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
     * Seed this actor's rows for many projects at once, in one query.
     *
     * The shared sidebar props ask the project policy about every row they send, and each ask
     * would otherwise be its own `project_memberships` read — fifteen of them on every request
     * in the application. Absence is memoised too: a project the actor has no row in has to
     * answer null from here rather than fall through to a query of its own.
     *
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

    /** Absence of an answer is the only safe cached answer once a membership changes. */
    public function flush(): void
    {
        $this->workspaces = [];
        $this->projects = [];
    }
}
