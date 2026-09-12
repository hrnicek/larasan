<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\AddTaskCollaborator;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Actions\CompleteTask;
use App\Domain\Task\Actions\DeleteTask;
use App\Domain\Task\Actions\UpdateTask;
use App\Domain\Task\Data\UpdateTaskData;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * @return array{Task, User, User}
 */
function taskOnBoardWith(ProjectAccessLevel $access, bool $archived, bool $completed): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $colleague = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->create(['archived_at' => $archived ? now() : null]);
    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();

    $task = Task::factory()->in($workspace)->create([
        'title' => 'Untouched',
        'completed_at' => $completed ? now() : null,
    ]);
    TaskProjectMembership::factory()->placing($task, $project)->create();

    return [$task, $actor, $colleague];
}

/**
 * @return array<string, mixed>
 */
function boardAccessSnapshot(Task $task): array
{
    $fresh = Task::query()->withTrashed()->findOrFail($task->id);

    return [
        'title' => $fresh->title,
        'completed' => $fresh->completed_at !== null,
        'assignee' => $fresh->assignee_id,
        'deleted' => $fresh->deleted_at !== null,
        'collaborators' => $fresh->collaborations()->count(),
    ];
}

dataset('task operations', [
    'update' => [
        fn (Task $task, User $actor): mixed => app(UpdateTask::class)->handle($task, $actor, new UpdateTaskData(title: 'Renamed', fields: ['title'])),
        'permission to change',
        false,
    ],
    'complete' => [
        fn (Task $task, User $actor): mixed => app(CompleteTask::class)->complete($task, $actor),
        'permission to change',
        false,
    ],
    'reopen' => [
        fn (Task $task, User $actor): mixed => app(CompleteTask::class)->reopen($task, $actor),
        'permission to change',
        true,
    ],
    'assign' => [
        fn (Task $task, User $actor, User $colleague): mixed => app(AssignTask::class)->handle($task, $actor, $colleague),
        'permission to assign',
        false,
    ],
    'add a collaborator' => [
        fn (Task $task, User $actor, User $colleague): mixed => app(AddTaskCollaborator::class)->handle($task, $actor, $colleague),
        'permission to assign',
        false,
    ],
    'delete' => [
        fn (Task $task, User $actor): mixed => app(DeleteTask::class)->handle($task, $actor),
        'permission to delete',
        false,
    ],
]);

it('refuses a caller outside http on a board that does not let them change tasks', function (
    ProjectAccessLevel $access,
    bool $archived,
    Closure $operation,
    string $refusal,
    bool $completed,
): void {
    [$task, $actor, $colleague] = taskOnBoardWith($access, $archived, $completed);
    $before = boardAccessSnapshot($task);

    expect(fn (): mixed => $operation($task, $actor, $colleague))->toThrow(TaskException::class, $refusal);

    expect(boardAccessSnapshot($task))->toBe($before);
})->with([
    'viewer' => [ProjectAccessLevel::Viewer, false],
    'commenter' => [ProjectAccessLevel::Commenter, false],
    'editor on an archived board' => [ProjectAccessLevel::Editor, true],
])->with('task operations');

it('still lets an editor of an active board do each of them', function (
    Closure $operation,
    string $refusal,
    bool $completed,
): void {
    [$task, $actor, $colleague] = taskOnBoardWith(ProjectAccessLevel::Editor, false, $completed);
    $before = boardAccessSnapshot($task);

    $operation($task, $actor, $colleague);

    expect(boardAccessSnapshot($task))->not->toBe($before);
})->with('task operations');
