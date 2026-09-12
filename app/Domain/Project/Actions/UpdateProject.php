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

        // Nullable columns: null clears the value.
        $project->fill([
            'description' => $data->description,
            'color' => $data->color,
            'icon' => $data->icon,
            'start_date' => $data->startDate,
            'due_date' => $data->dueDate,
        ]);

        // Non-nullable columns: null leaves the value unchanged.
        $project->fill(array_filter([
            'name' => $data->name,
            'slug' => $data->slug,
            'default_view' => $data->defaultView,
            'visibility' => $data->visibility,
        ], fn (mixed $value): bool => $value !== null));

        $changed = array_keys($project->getDirty());

        if ($changed === []) {
            return $project;
        }

        $project->save();

        $this->events->dispatch(new ProjectUpdated($project->id, $changed));

        return $project;
    }
}
