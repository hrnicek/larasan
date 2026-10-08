<?php

declare(strict_types=1);

namespace App\Domain\Placement\Actions;

use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Actions\CreateTask;
use App\Domain\Task\Data\CreateTaskData;
use App\Domain\Task\Exceptions\TaskException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateTaskInProject
{
    public function __construct(
        private CreateTask $createTask,
        private AttachTaskToProject $attachTask,
        private MoveTaskInProject $moveTask,
        private AssignTask $assignTask,
    ) {}

    public function handle(
        Project $project,
        User $actor,
        CreateTaskData $data,
        ?Section $section = null,
    ): TaskProjectMembership {
        if (! $project->allowsChangesBy($actor, Capability::TaskCreate)) {
            throw PlacementException::cannotPlaceTasks();
        }

        if ($section !== null && $section->project_id !== $project->id) {
            throw PlacementException::sectionBelongsToAnotherProject();
        }

        return DB::transaction(function () use ($project, $actor, $data, $section): TaskProjectMembership {
            // Assigned only once placed, since whether the assignee can reach the task depends on the project.
            $task = $this->createTask->handle($project->workspace, $actor, $data->withoutAssignee());

            $placement = $this->attachTask->handle($task, $project, $actor);

            if ($section !== null) {
                $this->moveTask->handle($placement, $actor, $section);
            }

            if ($data->assigneeId !== null) {
                $this->assignTask->handle(
                    $task,
                    $actor,
                    User::query()->find($data->assigneeId) ?? throw TaskException::assigneeIsNotAMember(),
                );
            }

            return $placement->refresh();
        });
    }
}
