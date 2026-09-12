<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Project\Support\ProjectOwners;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class GrantProjectAccess
{
    public function __construct(private ProjectOwners $owners) {}

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
            return DB::transaction(function () use ($existing, $level): ProjectMembership {
                if (! $level->canManageProject() && $this->owners->isLastOwner($existing)) {
                    throw ProjectException::projectNeedsAnOwner();
                }

                $existing->access_level = $level;
                $existing->save();

                return $existing;
            });
        }

        return ProjectMembership::query()->create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'access_level' => $level,
        ]);
    }
}
