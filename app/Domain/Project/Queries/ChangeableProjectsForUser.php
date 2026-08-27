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
 * The projects a person may change in one workspace, as one query.
 *
 * `VisibleProjectsForUser` is the same idea for reading, and this is deliberately its twin:
 * `Project::allowsChangesBy()` and `allowsCommentsBy()` state the rule for one project, and a
 * caller holding many — the task policy asking whether *any* of a task's boards is open to
 * the actor — must not ask the model per row (ADR-0005).
 *
 * The two halves that cannot be expressed per row are here rather than in the caller: an
 * explicit membership decides in both directions, and only its absence lets the project's
 * `default_access_level` answer.
 */
final readonly class ChangeableProjectsForUser
{
    /**
     * Projects whose contents this actor may change — cards, columns, documents.
     *
     * @return Builder<Project>
     */
    public function query(Workspace $workspace, User $user, Capability $capability): Builder
    {
        return $this->scoped($workspace, $user, $capability, ProjectAccessLevel::editingValues());
    }

    /**
     * Projects this actor may take part in the conversation of.
     *
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

        // `active()` rather than a condition written here: an archived project is read-only,
        // and that is the same scope every other reader of this table uses.
        $query = Project::query()->where('workspace_id', $workspace->id)->active();

        /*
         * Both halves of the workspace question first, because neither depends on the project:
         * a lapsed membership reaches nothing, and a capability the role does not hold is not
         * granted by any project. An impossible query rather than an early return keeps the
         * return type one thing.
         */
        if ($membership?->allows($capability) !== true) {
            return $query->whereRaw('1 = 0');
        }

        $granted = fn (Builder $projects): Builder => $projects
            ->whereHas('memberships', fn (Builder $memberships): Builder => $memberships
                ->where('user_id', $user->id)
                ->whereIn('access_level', $levels));

        if ($membership->role->isGuest()) {
            // A guest holds projects, never a workspace's default: the same sentence
            // `VisibleProjectsForUser` writes for reading.
            return $query->where($granted);
        }

        return $query->where(fn (Builder $projects): Builder => $projects
            ->where($granted)
            ->orWhere(fn (Builder $byDefault): Builder => $byDefault
                ->where('visibility', ProjectVisibility::Workspace->value)
                ->whereIn('default_access_level', $levels)
                /*
                 * Only where there is no row of their own. A Viewer named on a board the
                 * workspace may edit was restricted on purpose, and the default must not
                 * quietly hand back what that row took away.
                 */
                ->whereDoesntHave('memberships', fn (Builder $memberships): Builder => $memberships
                    ->where('user_id', $user->id))));
    }
}
