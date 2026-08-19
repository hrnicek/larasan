<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Events\ProjectArchived;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Archiving is reversible and destroys nothing: the project leaves the listings, its tasks
 * and memberships stay exactly as they were, and restoring puts it back. Deleting is a
 * different operation with a different confirmation.
 */
final readonly class ArchiveProject
{
    public function __construct(private Dispatcher $events) {}

    public function archive(Project $project, User $actor): Project
    {
        return $this->setArchived($project, $actor, archived: true);
    }

    public function restore(Project $project, User $actor): Project
    {
        return $this->setArchived($project, $actor, archived: false);
    }

    private function setArchived(Project $project, User $actor, bool $archived): Project
    {
        if (! $project->isManageableBy($actor)) {
            throw ProjectException::cannotManageProject();
        }

        if ($project->isArchived() === $archived) {
            return $project;
        }

        $project->forceFill(['archived_at' => $archived ? now() : null])->save();

        $this->events->dispatch(new ProjectArchived($project->id, $actor->id, $archived));

        return $project;
    }
}
