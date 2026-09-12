<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Models\User;

final readonly class GrantProjectAccess
{
    public function handle(
        Project $project,
        User $actor,
        User $member,
        ProjectAccessLevel $level,
    ): ProjectMembership {
        if ($actor->cannot('manageMembers', $project)) {
            throw ProjectException::cannotManageProject();
        }

        if (! $project->workspace->hasActiveMember($member->id)) {
            throw ProjectException::memberIsNotInTheWorkspace();
        }

        $existing = $project->memberships()->where('user_id', $member->id)->first();

        if ($existing instanceof ProjectMembership) {
            if ($this->wouldLeaveNoOwner($project, $existing, $level)) {
                throw ProjectException::projectNeedsAnOwner();
            }

            $existing->access_level = $level;
            $existing->save();

            return $existing;
        }

        return ProjectMembership::query()->create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'access_level' => $level,
        ]);
    }

    private function wouldLeaveNoOwner(
        Project $project,
        ProjectMembership $membership,
        ProjectAccessLevel $level,
    ): bool {
        if (! $membership->access_level->canManageProject() || $level->canManageProject()) {
            return false;
        }

        return $project->memberships()
            ->where('access_level', ProjectAccessLevel::Owner->value)
            ->count() === 1;
    }
}
