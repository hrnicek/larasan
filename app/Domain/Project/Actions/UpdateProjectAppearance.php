<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectIcon;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * How a project looks, and nothing else.
 *
 * `UpdateProject` takes the whole settings form and reads a missing nullable field as
 * "clear this", which is right for a form that renders every field and wrong for a
 * control that renders two: picking a colour from the project header through that Action
 * would erase the description, the start date and the due date nobody touched. So the
 * two appearance columns are their own operation, and null here still means clear —
 * a project must be able to lose its colour or its icon.
 */
final readonly class UpdateProjectAppearance
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Project $project, User $actor, ?ProjectColor $color, ?ProjectIcon $icon): Project
    {
        if (! $project->isManageableBy($actor)) {
            throw ProjectException::cannotManageProject();
        }

        $project->fill(['color' => $color, 'icon' => $icon]);

        $changed = array_keys($project->getDirty());

        if ($changed === []) {
            return $project;
        }

        $project->save();

        $this->events->dispatch(new ProjectUpdated($project->id, $changed));

        return $project;
    }
}
