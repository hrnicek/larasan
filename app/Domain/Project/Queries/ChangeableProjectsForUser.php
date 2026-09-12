<?php

declare(strict_types=1);

namespace App\Domain\Project\Queries;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query counterpart of Project::allowsChangesBy() and allowsCommentsBy(); keep the rules in sync.
 */
final readonly class ChangeableProjectsForUser
{
    /**
     * @return Builder<Project>
     */
    public function query(Workspace $workspace, User $user, Capability $capability): Builder
    {
        return $this->scoped($workspace, $user, $capability, ProjectAccessLevel::editingValues());
    }

    /**
     * @return Builder<Project>
     */
    public function commentable(Workspace $workspace, User $user): Builder
    {
        return $this->scoped(
            $workspace,
            $user,
            Capability::CommentCreate,
            ProjectAccessLevel::commentingValues(),
        );
    }

    /**
     * @param  list<string>  $levels  the access levels that answer yes to the question asked
     * @return Builder<Project>
     */
    private function scoped(Workspace $workspace, User $user, Capability $capability, array $levels): Builder
    {
        $membership = $workspace->membershipFor($user);

        $query = Project::query()->where('workspace_id', $workspace->id)->active();

        if ($membership?->allows($capability) !== true) {
            return $query->whereRaw('1 = 0');
        }

        $granted = fn (Builder $projects): Builder => $projects
            ->whereHas('memberships', fn (Builder $memberships): Builder => $memberships
                ->where('user_id', $user->id)
                ->whereIn('access_level', $levels));

        if ($membership->role->isGuest()) {
            return $query->where($granted);
        }

        return $query->where(fn (Builder $projects): Builder => $projects
            ->where($granted)
            ->orWhere(fn (Builder $byDefault): Builder => $byDefault
                ->where('visibility', ProjectVisibility::Workspace->value)
                ->whereIn('default_access_level', $levels)
                // An explicit membership row overrides the default, even when it grants less.
                ->whereDoesntHave('memberships', fn (Builder $memberships): Builder => $memberships
                    ->where('user_id', $user->id))));
    }
}
