<?php

declare(strict_types=1);

namespace App\Domain\Task\Policies;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Models\Task;
use App\Models\User;

/**
 * Every ability asks the workspace capability, because that is the whole answer today.
 *
 * **Project access cannot narrow it yet, and that is deliberate rather than forgotten.** A
 * task has no places until Phase 070 attaches it to projects; once it does, reading a task
 * will also require reaching one of the projects it appears in, and this policy is where
 * that half arrives. Saying so here is cheaper than a future reader deciding the check was
 * an oversight and adding a second one somewhere else.
 *
 * Resolved by auto-discovery, and `TaskPolicyTest` asserts that resolution — the
 * `app/Domain/<Context>/Policies` pairing is not the layout the framework documents, so a
 * namespace move would otherwise stop authorizing in silence.
 */
class TaskPolicy
{
    /**
     * A workspace member sees the workspace's tasks. A guest does not: they reach only what
     * they were explicitly given, which is a project (ADR-0006 with ADR-0010), and until
     * placement exists there is nothing that could have been given to them.
     */
    public function view(User $user, Task $task): bool
    {
        $membership = $task->workspace->membershipFor($user);

        return $membership?->status->grantsAccess() === true
            && ! $membership->role->isGuest();
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

    private function allows(User $user, Task $task, Capability $capability): bool
    {
        return $task->workspace->membershipFor($user)?->allows($capability) === true;
    }
}
