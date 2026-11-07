<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class SetProjectDefaultView
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Project $project, User $actor, ProjectDefaultView $view): Project
    {
        if (! $project->isManageableBy($actor)) {
            throw ProjectException::cannotManageProject();
        }

        $project->default_view = $view;

        if (! $project->isDirty()) {
            return $project;
        }

        $project->save();

        $this->events->dispatch(new ProjectUpdated($project->id, ['default_view']));

        return $project;
    }
}
