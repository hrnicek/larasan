<?php

declare(strict_types=1);

namespace App\Domain\Project\Actions;

use App\Domain\Project\Data\CreateProjectData;
use App\Domain\Project\Events\ProjectCreated;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Data\CreateSectionData;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Ordering\SparsePosition;
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
            // Only a derived slug is retried; a caller-supplied slug is reported as taken.
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

            // owner_id grants nothing; access comes from the membership row.
            ProjectMembership::query()->create([
                'project_id' => $project->id,
                'user_id' => $creator->id,
                'access_level' => ProjectAccessLevel::Owner,
            ]);

            $positions = SparsePosition::spread(count(Section::DEFAULT_NAMES));

            foreach (Section::DEFAULT_NAMES as $index => $name) {
                $section = new Section(['name' => $name, 'color' => CreateSectionData::DEFAULT_COLOR, 'position' => $positions[$index]]);
                $section->project_id = $project->id;
                $section->save();
            }

            return $project;
        });
    }
}
