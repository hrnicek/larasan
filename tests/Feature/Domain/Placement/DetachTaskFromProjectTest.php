<?php

declare(strict_types=1);

use App\Domain\Placement\Actions\DetachTaskFromProject;
use App\Domain\Placement\Events\TaskDetachedFromProject;
use App\Domain\Placement\Exceptions\PlacementException;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Event;

function detach(Task $task, Project $project, User $actor): void
{
    app(DetachTaskFromProject::class)->handle($task, $project, $actor);
}

/**
 * A placement made without going through the Action, for the cases where the actor under
 * test is not allowed to create one.
 */
function attachDirectly(Task $task, Project $project): TaskProjectMembership
{
    return TaskProjectMembership::factory()->placing($task, $project)->create();
}

it('removes the card and keeps the task', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attach($task, $project, $actor);

    detach($task, $project, $actor);

    // The task belongs to the workspace, not to the project it was shown in (ADR-0003).
    expect($project->placements()->count())->toBe(0)
        ->and($task->fresh())->not->toBeNull()
        ->and($task->fresh()?->workspace_id)->toBe($workspace->id);
});

it('leaves the task in the projects it is still in', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $other = Project::factory()->in($workspace)->create();
    ProjectMembership::factory()->in($other)->forUser($actor)->withAccess(ProjectAccessLevel::Editor)->create();
    $task = Task::factory()->in($workspace)->create();

    attach($task, $project, $actor);
    attach($task, $other, $actor);

    detach($task, $project, $actor);

    expect($task->placements()->pluck('project_id')->all())->toBe([$other->id]);
});

it('leaves the other cards in the project alone', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $going = Task::factory()->in($workspace)->create();
    $staying = Task::factory()->in($workspace)->create();

    attach($going, $project, $actor);
    $kept = attach($staying, $project, $actor);

    detach($going, $project, $actor);

    expect($project->placements()->pluck('id')->all())->toBe([$kept->id])
        // The survivors keep their slots: detaching is not a reordering.
        ->and($kept->fresh()?->position)->toBe($kept->position);
});

it('forgets which column the task was in', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $section = Section::factory()->in($project)->create();
    $task = Task::factory()->in($workspace)->create();
    $placement = attach($task, $project, $actor);
    $placement->forceFill(['section_id' => $section->id])->save();

    detach($task, $project, $actor);

    // No soft delete: a hidden row would still hold the slot and the unique pair, so
    // re-attaching the task would collide with its own tombstone.
    expect($section->placements()->count())->toBe(0)
        ->and($project->placements()->count())->toBe(0);
});

it('lets a detached task be attached again', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attach($task, $project, $actor);

    detach($task, $project, $actor);
    $again = attach($task, $project, $actor);

    expect($again->exists)->toBeTrue()
        ->and($project->placements()->count())->toBe(1);
});

it('does nothing when the task is not in the project', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    Event::fake();
    detach($task, $project, $actor);

    // A repeated request, or a board that was already stale when the user clicked.
    Event::assertNotDispatched(TaskDetachedFromProject::class);
    expect($task->fresh())->not->toBeNull();
});

it('refuses an actor who may only view the project', function (): void {
    [$workspace, $project, $actor] = placeableProject(ProjectAccessLevel::Viewer);
    $task = Task::factory()->in($workspace)->create();
    $placement = attachDirectly($task, $project);

    expect(fn () => detach($task, $project, $actor))
        ->toThrow(PlacementException::class, 'You do not have permission to change what this project holds.');

    expect($placement->fresh())->not->toBeNull();
});

it('refuses somebody from another workspace entirely', function (): void {
    [$workspace, $project] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attachDirectly($task, $project);

    $stranger = memberOf(Workspace::factory()->create());

    expect(fn () => detach($task, $project, $stranger))->toThrow(PlacementException::class);

    expect($project->placements()->count())->toBe(1);
});

it('announces the detachment', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attach($task, $project, $actor);

    Event::fake();
    detach($task, $project, $actor);

    Event::assertDispatched(TaskDetachedFromProject::class, fn (TaskDetachedFromProject $event): bool => $event->taskId === $task->id
        && $event->projectId === $project->id
        && $event->detachedById === $actor->id);
});
