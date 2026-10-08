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
 * Query counterpart of Project::isVisibleTo(); keep the rules in sync.
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
            return $query->whereRaw('1 = 0');
        }

        if (! $includeArchived) {
            $query->active();
        }

        $explicit = fn (Builder $projects): Builder => $projects
            ->whereHas('memberships', fn (Builder $memberships): Builder => $memberships->where('user_id', $user->id));

        if ($membership->role->isGuest()) {
            return $query->where($explicit);
        }

        return $query->where(fn (Builder $projects): Builder => $projects
            ->where('visibility', ProjectVisibility::Workspace->value)
            ->orWhere($explicit));
    }
}
