<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Models\User;

/**
 * Take somebody's access to a project away.
 *
 * What they wrote stays: their tasks, their comments and their activity are a record of what
 * happened, and removing them from a project is a decision about what they may reach next.
 *
 * The last owner cannot be removed, for the same reason they cannot be demoted — a project with no
 * owner row is one nobody can manage.
 */
final readonly class RevokeProjectAccess
{
    public function handle(Project $project, User $actor, ProjectMembership $membership): void
    {
        if ($actor->cannot('manageMembers', $project)) {
            throw ProjectException::cannotManageProject();
        }

        // A membership id that belongs to another project is a 404's worth of information, and
        // the caller may not be an HTTP request.
        if ($membership->project_id !== $project->id) {
            throw ProjectException::membershipIsNotOnThisProject();
        }

        if ($membership->access_level->canManageProject() && $this->isTheOnlyOwner($project)) {
            throw ProjectException::projectNeedsAnOwner();
        }

        $membership->delete();
    }

    private function isTheOnlyOwner(Project $project): bool
    {
        return $project->memberships()
            ->where('access_level', ProjectAccessLevel::Owner->value)
            ->count() === 1;
    }
}
