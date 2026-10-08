<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectStar;
use App\Models\User;

/**
 * No access check: a user who lost access to a project must still be able to unstar it.
 */
final readonly class UnstarProject
{
    public function handle(Project $project, User $actor): void
    {
        $star = $project->stars()->where('user_id', $actor->id)->first();

        if (! $star instanceof ProjectStar) {
            return;
        }

        $star->delete();
    }
}
