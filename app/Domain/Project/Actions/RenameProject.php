<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

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
