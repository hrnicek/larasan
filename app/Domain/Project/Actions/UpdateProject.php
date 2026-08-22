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

        /*
         * Null means two different things, and which one depends on the column. A nullable
         * column takes it as "clear this": a project must be able to lose its colour, its
         * description or a due date that no longer applies, and the first version of this
         * Action filtered every null out, which made those fields write-once.
         */
        $project->fill([
            'description' => $data->description,
            'color' => $data->color,
            'icon' => $data->icon,
            'start_date' => $data->startDate,
            'due_date' => $data->dueDate,
        ]);

        /*
         * The rest have no null to mean anything by — the columns are not nullable — so
         * null is "leave it alone". A slug the caller did not supply stays as it is:
         * re-deriving it from a renamed project breaks every link anyone saved, which is
         * the renamer's decision to make rather than a side effect of renaming.
         */
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
