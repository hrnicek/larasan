<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectStar;
use App\Models\User;

/**
 * Take a project off the top of one person's sidebar.
 *
 * No reach check, deliberately, as `UnfollowTask` has none: somebody who has lost access to a
 * project must still be able to clear it out of their own sidebar. Unstarring something that is
 * not starred is a no-op, because the outcome they asked for is already true.
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
