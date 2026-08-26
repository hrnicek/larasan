<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Models\User;

/**
 * Give somebody access to a project, or change the access they already have.
 *
 * One Action for both, because they are the same sentence: *this person has this access here*.
 * `UNIQUE(project_id, user_id)` says so too — granting twice is one row, not two (ADR-0006).
 *
 * Two invariants the schema cannot hold:
 *
 * - the person has to be an active member of the project's workspace. `ProjectMembership::booted`
 *   refuses it as well, because these rows are written from several places; this asks first so the
 *   refusal is the Action's rather than a model event's.
 * - **the last owner cannot be demoted.** Managing a project needs an explicit `owner` row
 *   (`Project::isManageableBy`), so a project whose last owner became an editor is a project
 *   nobody can manage — not its workspace's owner, not anybody.
 */
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
