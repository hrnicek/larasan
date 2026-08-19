<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Policies;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * Resolved by Laravel's policy auto-discovery: `guessPolicyName()` walks the model's
 * namespace segments upward, so no registration is needed for
 * `App\Domain\Workspace\Models\Workspace`. `WorkspacePolicyTest` asserts that resolution,
 * because a namespace move would otherwise stop authorizing silently.
 */
class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->membershipFor($user)?->status->grantsAccess() ?? false;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, Capability::WorkspaceManage);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, Capability::WorkspaceDelete);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, Capability::WorkspaceMembersManage);
    }

    public function createProject(User $user, Workspace $workspace): bool
    {
        return $this->allows($user, $workspace, Capability::ProjectCreate);
    }

    private function allows(User $user, Workspace $workspace, Capability $capability): bool
    {
        return $workspace->membershipFor($user)?->allows($capability) ?? false;
    }
}
