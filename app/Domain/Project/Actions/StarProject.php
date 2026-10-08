<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectStar;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class StarProject
{
    public function handle(Project $project, User $actor): ProjectStar
    {
        if ($actor->cannot('view', $project)) {
            throw ProjectException::cannotStarUnreachableProject();
        }

        $existing = $project->stars()->where('user_id', $actor->id)->first();

        if ($existing instanceof ProjectStar) {
            return $existing;
        }

        try {
            $star = new ProjectStar(['project_id' => $project->id, 'user_id' => $actor->id]);
            $star->save();

            return $star;
        } catch (UniqueConstraintViolationException) {
            // Lost a concurrent insert; return the row that won.
            return $project->stars()->where('user_id', $actor->id)->firstOrFail();
        }
    }
}
