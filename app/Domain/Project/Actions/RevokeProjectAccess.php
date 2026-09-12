<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Project\Support\ProjectOwners;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class RevokeProjectAccess
{
    public function __construct(private ProjectOwners $owners) {}

    public function handle(Project $project, User $actor, ProjectMembership $membership): void
    {
        if ($actor->cannot('manageMembers', $project)) {
            throw ProjectException::cannotManageProject();
        }

        if ($membership->project_id !== $project->id) {
            throw ProjectException::membershipIsNotOnThisProject();
        }

        DB::transaction(function () use ($membership): void {
            if ($this->owners->isLastOwner($membership)) {
                throw ProjectException::projectNeedsAnOwner();
            }

            $membership->delete();
        });
    }
}
