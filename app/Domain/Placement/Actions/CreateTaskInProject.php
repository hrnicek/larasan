<?php

declare(strict_types=1);

namespace App\Domain\Placement\Actions;

use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Actions\CreateTask;
use App\Domain\Task\Data\CreateTaskData;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adding a task from a board: the task and the card it appears as, created together.
 *
 * A task is still not created into a project (ADR-0003) — it is created into the workspace
 * and then placed. What this Action adds is that the two halves cannot half-happen: a task
 * with no card is a task nobody looking at the board can find, and the person who typed a
 * name into a column meant both.
 *
 * It composes the existing Actions rather than repeating them, so the workspace check, the
 * cross-workspace refusal, the append and every event stay in one place each.
 */
final readonly class CreateTaskInProject
{
    public function __construct(
        private CreateTask $createTask,
        private AttachTaskToProject $attachTask,
        private MoveTaskInProject $moveTask,
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
            $task = $this->createTask->handle($project->workspace, $actor, $data);

            $placement = $this->attachTask->handle($task, $project, $actor);

            if ($section !== null) {
                $this->moveTask->handle($placement, $actor, $section);
            }

            return $placement->refresh();
        });
    }
}
