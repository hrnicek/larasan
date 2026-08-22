<?php

declare(strict_types=1);

namespace App\Domain\Placement\Policies;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

/**
 * A placement has no authorization of its own: a card belongs to a project, and who may
 * move it or take it off the board is the project's answer. This asks
 * `Project::allowsChangesBy()` rather than restating the rule, so the Actions, the requests
 * and the UI cannot drift apart — the same shape as `SectionPolicy`.
 *
 * Attaching is not here: there is no placement yet to judge. `ProjectPolicy::placeTask()`
 * answers that one, on the thing that does exist.
 *
 * Moving and detaching are both `task.update`. Detaching a card removes where a task
 * appears, not the task, so `task.delete` would be the wrong question — a commenter must not
 * be able to empty a board, and a member who may edit tasks may rearrange them.
 */
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
        return $placement->project->allowsChangesBy($user, Capability::TaskUpdate);
    }
}
