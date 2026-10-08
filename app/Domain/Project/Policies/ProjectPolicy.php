<?php

declare(strict_types=1);

namespace App\Domain\Project\Policies;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

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

    public function createSection(User $user, Project $project): bool
    {
        return $project->allowsChangesBy($user, Capability::SectionCreate);
    }

    public function createPage(User $user, Project $project): bool
    {
        return $project->allowsChangesBy($user, Capability::PageCreate);
    }

    /**
     * Placing an existing task changes that task, so it needs task.update rather than task.create.
     */
    public function placeTask(User $user, Project $project): bool
    {
        return $project->allowsChangesBy($user, Capability::TaskUpdate);
    }

    public function createTask(User $user, Project $project): bool
    {
        return $project->allowsChangesBy($user, Capability::TaskCreate);
    }

    public function comment(User $user, Project $project): bool
    {
        return $project->allowsCommentsBy($user);
    }
}
