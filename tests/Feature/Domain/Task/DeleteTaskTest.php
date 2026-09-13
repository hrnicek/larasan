<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\DeleteTask;
use App\Domain\Task\Events\TaskDeleted;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\ReachableTasks;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Event;

it('deletes softly, so what hangs off the task survives it', function (): void {
    [$task, $actor] = taskEditableBy();

    app(DeleteTask::class)->handle($task, $actor);

    expect(Task::query()->whereKey($task->id)->exists())->toBeFalse()
        ->and(Task::query()->withTrashed()->whereKey($task->id)->exists())->toBeTrue();
});

it('takes the subtasks that are in no project of their own with it', function (): void {
    [$parent, $actor] = taskEditableBy();
    $child = Task::factory()->childOf($parent)->create();
    $grandchild = Task::factory()->childOf($child)->create();

    Event::fake([TaskDeleted::class]);

    app(DeleteTask::class)->handle($parent, $actor);

    expect(Task::query()->whereKey([$parent->id, $child->id, $grandchild->id])->exists())->toBeFalse()
        ->and(Task::query()->withTrashed()->whereKey([$child->id, $grandchild->id])->count())->toBe(2);

    foreach ([$parent, $child, $grandchild] as $deleted) {
        Event::assertDispatched(TaskDeleted::class, fn (TaskDeleted $event): bool => $event->taskId === $deleted->id);
    }
});

it('promotes a subtask filed in a project of its own instead of deleting it', function (): void {
    [$parent, $actor] = taskEditableBy();
    $child = Task::factory()->childOf($parent)->create();
    TaskProjectMembership::factory()->placing($child, Project::factory()->in($parent->workspace)->create())->create();

    app(DeleteTask::class)->handle($parent, $actor);

    expect($child->fresh()?->parent_id)->toBeNull()
        ->and($child->fresh()?->deleted_at)->toBeNull();
});

it('leaves a grandchild under a promoted subtask', function (): void {
    [$parent, $actor] = taskEditableBy();
    $child = Task::factory()->childOf($parent)->create();
    TaskProjectMembership::factory()->placing($child, Project::factory()->in($parent->workspace)->create())->create();
    $grandchild = Task::factory()->childOf($child)->create();

    app(DeleteTask::class)->handle($parent, $actor);

    expect($grandchild->fresh()?->parent_id)->toBe($child->id)
        ->and($grandchild->fresh()?->deleted_at)->toBeNull();
});

it('never leaves a subtask of a private task behind as workspace work', function (): void {
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace);
    $colleague = memberOf($workspace);
    $private = Project::factory()->in($workspace)->private()->create();
    ProjectMembership::factory()->in($private)->forUser($owner)->withAccess(ProjectAccessLevel::Owner)->create();

    $parent = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($parent, $private)->create();
    $subtask = Task::factory()->childOf($parent)->create(['title' => 'Negotiate severance']);

    app(DeleteTask::class)->handle($parent, $owner);

    expect(app(ReachableTasks::class)->constrain(Task::query(), $workspace, $colleague)->pluck('title')->all())->toBe([])
        ->and(Task::query()->whereKey($subtask->id)->exists())->toBeFalse();
});

it('announces the deletion with the ids a listener will need', function (): void {
    [$task, $actor] = taskEditableBy();
    Event::fake();

    app(DeleteTask::class)->handle($task, $actor);

    Event::assertDispatched(TaskDeleted::class, fn (TaskDeleted $event): bool => $event->taskId === $task->id
        && $event->workspaceId === $task->workspace_id
        && $event->deletedById === $actor->id);
});

it('refuses an actor without the task.delete capability', function (): void {
    [$task, $guest] = taskEditableBy(WorkspaceRole::Guest);

    expect(fn () => app(DeleteTask::class)->handle($task, $guest))
        ->toThrow(TaskException::class, 'permission to delete');

    expect(Task::query()->whereKey($task->id)->exists())->toBeTrue();
});

it('refuses somebody from another workspace', function (): void {
    [$task] = taskEditableBy();
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    expect(fn () => app(DeleteTask::class)->handle($task, $outsider))->toThrow(TaskException::class);

    expect(Task::query()->whereKey($task->id)->exists())->toBeTrue();
});

it('keeps the subtasks when the deletion is refused', function (): void {
    [$parent, $guest] = taskEditableBy(WorkspaceRole::Guest);
    $child = Task::factory()->childOf($parent)->create();

    expect(fn () => app(DeleteTask::class)->handle($parent, $guest))->toThrow(TaskException::class);

    expect($child->fresh()?->parent_id)->toBe($parent->id);
});
