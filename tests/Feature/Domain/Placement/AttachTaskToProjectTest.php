<?php

declare(strict_types=1);

use App\Domain\Placement\Actions\AttachTaskToProject;
use App\Domain\Placement\Events\TaskAttachedToProject;
use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * @return array{Workspace, Project, User}
 */
function placeableProject(
    ProjectAccessLevel $access = ProjectAccessLevel::Editor,
    WorkspaceRole $role = WorkspaceRole::Member,
): array {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create();

    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();

    return [$workspace, $project, $actor];
}

function attach(Task $task, Project $project, User $actor): TaskProjectMembership
{
    return app(AttachTaskToProject::class)->handle($task, $project, $actor);
}

it('puts a task in a project without taking it out of the workspace', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $placement = attach($task, $project, $actor);

    expect($placement->task_id)->toBe($task->id)
        ->and($placement->project_id)->toBe($project->id)
        ->and($task->fresh()?->workspace_id)->toBe($workspace->id)
        ->and($project->tasks()->pluck('tasks.id')->all())->toBe([$task->id]);
});

it('attaches a task ungrouped', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    expect(attach($task, $project, $actor)->isUngrouped())->toBeTrue();
});

it('appends each new placement after the last', function (): void {
    [$workspace, $project, $actor] = placeableProject();

    $positions = collect(range(1, 3))
        ->map(fn (): int => attach(Task::factory()->in($workspace)->create(), $project, $actor)->position)
        ->all();

    expect($positions)->toBe(SparsePosition::spread(3));
});

it('treats a second attach as the same attach', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $first = attach($task, $project, $actor);
    $second = attach($task, $project, $actor);

    expect($second->id)->toBe($first->id)
        ->and($project->placements()->count())->toBe(1);
});

it('keeps a task where it already was when it is attached again', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    $placement = attach($task, $project, $actor);
    $placement->forceFill(['position' => 5 * SparsePosition::GAP])->save();

    expect(attach($task, $project, $actor)->position)->toBe(5 * SparsePosition::GAP);
});

it('refuses a task from another workspace', function (): void {
    [, $project, $actor] = placeableProject();
    $elsewhere = Task::factory()->create();

    // Both rows exist, so no foreign key can refuse the pair; the Action has to. See ADR-0003.
    expect(fn (): TaskProjectMembership => attach($elsewhere, $project, $actor))
        ->toThrow(PlacementException::class, 'That task is not in this workspace.');

    expect($project->placements()->count())->toBe(0);
});

it('refuses an actor who may only view the project', function (): void {
    [$workspace, $project, $actor] = placeableProject(ProjectAccessLevel::Viewer);
    $task = Task::factory()->in($workspace)->create();

    expect(fn (): TaskProjectMembership => attach($task, $project, $actor))
        ->toThrow(PlacementException::class, 'You do not have permission to change what this project holds.');
});

it('refuses a guest who cannot reach the project at all', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    $task = Task::factory()->in($workspace)->create();

    expect(fn (): TaskProjectMembership => attach($task, $project, $guest))
        ->toThrow(PlacementException::class);
});

it('refuses a workspace member who is not in the project', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();

    expect(fn (): TaskProjectMembership => attach($task, $project, $outsider))
        ->toThrow(PlacementException::class);
});

it('announces the placement it created', function (): void {
    Event::fake();

    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    $placement = attach($task, $project, $actor);

    Event::assertDispatched(TaskAttachedToProject::class, fn (TaskAttachedToProject $event): bool => $event->placementId === $placement->id
        && $event->taskId === $task->id
        && $event->projectId === $project->id
        && $event->attachedById === $actor->id);
});

it('says nothing when the task was already there', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attach($task, $project, $actor);

    Event::fake();
    attach($task, $project, $actor);

    Event::assertNotDispatched(TaskAttachedToProject::class);
});

it('lets one task be attached to two projects', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $other = Project::factory()->in($workspace)->create();
    ProjectMembership::factory()->in($other)->forUser($actor)->withAccess(ProjectAccessLevel::Editor)->create();
    $task = Task::factory()->in($workspace)->create();

    attach($task, $project, $actor);
    attach($task, $other, $actor);

    expect($task->placements()->count())->toBe(2)
        ->and(Task::query()->count())->toBe(1);
});
