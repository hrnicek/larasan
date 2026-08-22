<?php

declare(strict_types=1);

namespace App\Domain\Task\Policies;

use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Models\Task;
use App\Models\User;

/**
 * Two questions, asked in this order: can the actor reach this task at all, and does their
 * workspace role allow the thing they are trying to do.
 *
 * Reach is where placement arrives (TASK-070-017). A task appears in projects, and a project
 * can be private, so "a member of the workspace" is no longer the whole answer: a task that
 * appears **only** in projects the actor cannot open is not theirs to read, and a guest —
 * who reaches only what they were explicitly given — sees a task exactly when it appears in
 * a project they were given (ADR-0003 with ADR-0006 and ADR-0010).
 *
 * Resolved by auto-discovery, and `TaskPolicyTest` asserts that resolution — the
 * `app/Domain/<Context>/Policies` pairing is not the layout the framework documents, so a
 * namespace move would otherwise stop authorizing in silence.
 */
class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        $membership = $task->workspace->membershipFor($user);

        if ($membership?->status->grantsAccess() !== true) {
            return false;
        }

        if ($this->appearsInAProjectVisibleTo($user, $task)) {
            return true;
        }

        /*
         * A task in no project at all is workspace work — the inbox, a quick capture, a
         * subtask nobody has filed yet — and a member of the workspace may read it. A guest
         * may not: they hold projects, and a task with no project was never given to them.
         */
        return ! $membership->role->isGuest() && ! $task->placements()->exists();
    }

    public function update(User $user, Task $task): bool
    {
        return $this->allows($user, $task, Capability::TaskUpdate);
    }

    public function complete(User $user, Task $task): bool
    {
        // Completion is an edit of the task's own state, so it asks the same capability the
        // Action does rather than inventing a `task.complete` nobody grants.
        return $this->allows($user, $task, Capability::TaskUpdate);
    }

    public function assign(User $user, Task $task): bool
    {
        return $this->allows($user, $task, Capability::TaskAssign);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->allows($user, $task, Capability::TaskDelete);
    }

    /**
     * Reach first, then capability. A capability is what the workspace lets somebody do to
     * the tasks they can already see; on its own it would let a member edit a card inside a
     * private project they were never added to.
     */
    private function allows(User $user, Task $task, Capability $capability): bool
    {
        return $this->view($user, $task)
            && $task->workspace->membershipFor($user)?->allows($capability) === true;
    }

    /**
     * Asked as one query rather than per placement: a task can be in many projects, and
     * `Project::isVisibleTo()` per row is the N+1 that `VisibleProjectsForUser` exists to
     * prevent (ADR-0005). Archived projects count — an archived board is read-only, not
     * hidden.
     */
    private function appearsInAProjectVisibleTo(User $user, Task $task): bool
    {
        $visible = app(VisibleProjectsForUser::class)
            ->query($task->workspace, $user, includeArchived: true)
            ->select('projects.id');

        return $task->placements()->whereIn('project_id', $visible)->exists();
    }
}
