<?php

declare(strict_types=1);

namespace App\Domain\Project\Queries;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The projects a person may see in one workspace, as one query.
 *
 * The same rule as `Project::isVisibleTo()`, expressed in SQL rather than per row: an
 * explicit membership always counts, `visibility = workspace` counts for workspace members
 * without one, and never for a guest. It lives here rather than in each caller because a
 * listing that forgets the guest clause leaks a project list, and a listing that asks the
 * model per row is an N+1 (ADR-0005: the scope is proven inside the query).
 */
final readonly class VisibleProjectsForUser
{
    /**
     * @return Collection<int, Project>
     */
    public function __invoke(Workspace $workspace, User $user, bool $includeArchived = false): Collection
    {
        return $this->query($workspace, $user, $includeArchived)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Builder<Project>
     */
    public function query(Workspace $workspace, User $user, bool $includeArchived = false): Builder
    {
        $membership = $workspace->membershipFor($user);

        $query = Project::query()->where('workspace_id', $workspace->id);

        if ($membership?->status->grantsAccess() !== true) {
            /*
             * Not a member of the workspace at all: no project in it is visible, whatever
             * a stale project_memberships row might say. Returning an impossible query
             * rather than an empty collection keeps the return type one thing.
             */
            return $query->whereRaw('1 = 0');
        }

        if (! $includeArchived) {
            $query->active();
        }

        $explicit = fn (Builder $projects): Builder => $projects
            ->whereHas('memberships', fn (Builder $memberships): Builder => $memberships->where('user_id', $user->id));

        if ($membership->role->isGuest()) {
            // A guest sees exactly what they were given. Visibility grants them nothing.
            return $query->where($explicit);
        }

        return $query->where(fn (Builder $projects): Builder => $projects
            ->where('visibility', ProjectVisibility::Workspace->value)
            ->orWhere($explicit));
    }
}
