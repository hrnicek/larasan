<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Models\User;

final readonly class RevokeProjectAccess
{
    public function handle(Project $project, User $actor, ProjectMembership $membership): void
    {
        if ($actor->cannot('manageMembers', $project)) {
            throw ProjectException::cannotManageProject();
        }

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
