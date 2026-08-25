<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectStar;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Pin a project to the top of one person's sidebar.
 *
 * Starring is not editing: anybody who can **read** the project may star it, which is why this
 * asks the policy's `view` rather than a capability — the same rule `FollowTask` follows. What
 * it will not do is put a project somebody cannot open at the top of their sidebar.
 *
 * No event: a star is one person's shortcut and nothing in the application reacts to it, unlike
 * a follow, which decides who an inbox notification reaches.
 */
final readonly class StarProject
{
    public function handle(Project $project, User $actor): ProjectStar
    {
        if ($actor->cannot('view', $project)) {
            throw ProjectException::cannotStarUnreachableProject();
        }

        $existing = $project->stars()->where('user_id', $actor->id)->first();

        // Starring twice is starring once: the same request arriving again, not a second star.
        if ($existing instanceof ProjectStar) {
            return $existing;
        }

        try {
            $star = new ProjectStar(['project_id' => $project->id, 'user_id' => $actor->id]);
            $star->save();

            return $star;
        } catch (UniqueConstraintViolationException) {
            /*
             * Two clicks, or two devices, at the same moment. `UNIQUE(project_id, user_id)` made
             * that an error rather than two rows, and the row that won is the answer.
             */
            return $project->stars()->where('user_id', $actor->id)->firstOrFail();
        }
    }
}
