<?php

declare(strict_types=1);

namespace App\Domain\Project\Support;

use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;

final readonly class ProjectOwners
{
    /** Call inside the transaction that changes the membership, so the owner rows stay locked until it ends. */
    public function isLastOwner(ProjectMembership $membership): bool
    {
        // PostgreSQL refuses FOR UPDATE with an aggregate, so the rows themselves are fetched and locked.
        $owners = ProjectMembership::query()
            ->where('project_id', $membership->project_id)
            ->where('access_level', ProjectAccessLevel::Owner->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        return $owners === [$membership->id];
    }
}
