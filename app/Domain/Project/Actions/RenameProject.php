<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * What a project is called, and nothing else.
 *
 * The same reason `UpdateProjectAppearance` exists: `UpdateProject` takes the whole settings
 * form and reads an absent nullable field as a deliberate clearing, so a rename typed into the
 * sidebar's one-field dialog would have emptied the description and both dates.
 *
 * The slug is deliberately left alone, as it is there — re-deriving it from a new name breaks
 * every link anybody saved, and that is the renamer's decision to make from the settings form
 * rather than a side effect of correcting a typo.
 */
final readonly class RenameProject
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Project $project, User $actor, string $name): Project
    {
        if (! $project->isManageableBy($actor)) {
            throw ProjectException::cannotManageProject();
        }

        $project->name = $name;

        if (! $project->isDirty()) {
            return $project;
        }

        $project->save();

        $this->events->dispatch(new ProjectUpdated($project->id, ['name']));

        return $project;
    }
}
