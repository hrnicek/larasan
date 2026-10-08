<?php

declare(strict_types=1);

namespace App\Domain\Placement\Policies;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

class TaskProjectMembershipPolicy
{
    public function view(User $user, TaskProjectMembership $placement): bool
    {
        return $placement->project->isVisibleTo($user);
    }

    public function update(User $user, TaskProjectMembership $placement): bool
    {
        return $placement->project->allowsChangesBy($user, Capability::TaskUpdate);
    }

    public function delete(User $user, TaskProjectMembership $placement): bool
    {
        // Detaching removes a card, not the task, so it requires task.update rather than task.delete.
        return $placement->project->allowsChangesBy($user, Capability::TaskUpdate);
    }
}
