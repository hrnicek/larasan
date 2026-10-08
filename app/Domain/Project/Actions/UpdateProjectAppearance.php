<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectIcon;
use App\Domain\Shared\ValueObjects\AccentColor;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class UpdateProjectAppearance
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Project $project, User $actor, ?AccentColor $color, ?ProjectIcon $icon): Project
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
