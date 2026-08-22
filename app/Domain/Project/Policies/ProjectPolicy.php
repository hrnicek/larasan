<?php

declare(strict_types=1);

namespace App\Domain\Project\Policies;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

/**
 * Every ability asks both halves where both apply: the workspace capability decides
 * whether the actor may do this kind of thing at all, the project access level decides
 * whether they may do it here (ADR-0006 with ADR-0010). Holding `task.create` in the
 * workspace grants nothing in a project the actor may only view.
 *
 * Resolved by auto-discovery, and `ProjectPolicyTest` asserts that resolution — the
 * `app/Domain/<Context>/Policies` pairing is not the layout the framework documents, so a
 * namespace move would otherwise stop authorizing in silence.
 */
class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $project->isVisibleTo($user);
    }

    public function update(User $user, Project $project): bool
    {
        return $project->isManageableBy($user);
    }

    public function archive(User $user, Project $project): bool
    {
        return $project->isManageableBy($user);
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $project->isManageableBy($user);
    }

    public function delete(User $user, Project $project): bool
    {
        return $project->workspace->membershipFor($user)?->allows(Capability::ProjectDelete) === true
            && $project->memberFor($user)?->access_level->canManageProject() === true;
    }

    /**
     * Adding a column, asked of the project because there is no section yet to judge.
     */
    public function createSection(User $user, Project $project): bool
    {
        return $project->allowsChangesBy($user, Capability::SectionCreate);
    }

    /**
     * Putting a task on this board, asked of the project because there is no placement yet
     * to judge. `task.update` rather than `task.create`: attaching an existing task changes
     * where it appears, and creating one is `createTask()` below.
     */
    public function placeTask(User $user, Project $project): bool
    {
        return $project->allowsChangesBy($user, Capability::TaskUpdate);
    }

    /**
     * Creating a task on this board is a change to what the project holds, so it asks the
     * same question placing an existing one does — including that an archived project is
     * read-only (TASK-050-013).
     */
    public function createTask(User $user, Project $project): bool
    {
        return $project->allowsChangesBy($user, Capability::TaskCreate);
    }

    public function comment(User $user, Project $project): bool
    {
        return $this->capableAndAllowed($user, $project, Capability::CommentCreate)
            && $project->memberFor($user)?->access_level->canComment() === true;
    }

    /**
     * The workspace half, plus the read check — an actor who cannot see the project cannot
     * act in it, whatever their access level row says.
     */
    private function capableAndAllowed(User $user, Project $project, Capability $capability): bool
    {
        return $project->isVisibleTo($user)
            && $project->workspace->membershipFor($user)?->allows($capability) === true;
    }
}
