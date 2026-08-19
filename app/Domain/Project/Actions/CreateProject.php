<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Data\CreateProjectData;
use App\Domain\Project\Events\ProjectCreated;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class CreateProject
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Workspace $workspace, User $creator, CreateProjectData $data): Project
    {
        if (! $workspace->membershipFor($creator)?->allows(Capability::ProjectCreate)) {
            throw ProjectException::cannotCreateProjects();
        }

        try {
            $project = $this->create($workspace, $creator, $data);
        } catch (UniqueConstraintViolationException $exception) {
            // Only a slug this Action derived may be retried — a caller's slug is their
            // input, and creating their project at a different address is worse than
            // telling them it is taken. Same rule as CreateWorkspace.
            if ($data->slug !== null) {
                throw $exception;
            }

            $project = $this->create($workspace, $creator, $data);
        }

        $this->events->dispatch(new ProjectCreated($project->id, $workspace->id, $creator->id));

        return $project;
    }

    private function create(Workspace $workspace, User $creator, CreateProjectData $data): Project
    {
        return DB::transaction(function () use ($workspace, $creator, $data): Project {
            $project = new Project([
                'name' => $data->name,
                'slug' => $data->slug ?? Project::slugFor($workspace, $data->name),
                'description' => $data->description,
                'color' => $data->color,
                'icon' => $data->icon,
                'default_view' => $data->defaultView,
                'visibility' => $data->visibility,
                'start_date' => $data->startDate,
                'due_date' => $data->dueDate,
            ]);

            $project->workspace_id = $workspace->id;
            $project->owner_id = $creator->id;
            $project->created_by = $creator->id;
            $project->save();

            /*
             * The creator is an owner of the project, not merely its `owner_id`. A private
             * project without this row is invisible to its own creator, and the column
             * alone grants nothing — the access level does.
             */
            ProjectMembership::query()->create([
                'project_id' => $project->id,
                'user_id' => $creator->id,
                'access_level' => ProjectAccessLevel::Owner,
            ]);

            return $project;
        });
    }
}
