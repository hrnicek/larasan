<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Data\UpdateProjectData;
use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class UpdateProject
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Project $project, User $actor, UpdateProjectData $data): Project
    {
        if (! $project->isManageableBy($actor)) {
            throw ProjectException::cannotManageProject();
        }

        $project->fill(array_filter([
            'name' => $data->name,
            'slug' => $data->slug,
            'description' => $data->description,
            'color' => $data->color,
            'icon' => $data->icon,
            'default_view' => $data->defaultView,
            'visibility' => $data->visibility,
            'start_date' => $data->startDate,
            'due_date' => $data->dueDate,
        ], fn (mixed $value): bool => $value !== null));

        /*
         * A slug the caller did not supply stays as it is. Re-deriving it from a renamed
         * project breaks every link anyone saved, which is the renamer's decision to make
         * rather than a side effect of renaming.
         */
        $changed = array_keys($project->getDirty());

        if ($changed === []) {
            return $project;
        }

        $project->save();

        $this->events->dispatch(new ProjectUpdated($project->id, $changed));

        return $project;
    }
}
