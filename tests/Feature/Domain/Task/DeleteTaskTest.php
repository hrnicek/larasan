<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\DeleteTask;
use App\Domain\Task\Events\TaskDeleted;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Event;

it('deletes softly, so what hangs off the task survives it', function (): void {
    [$task, $actor] = taskEditableBy();

    app(DeleteTask::class)->handle($task, $actor);

    expect(Task::query()->whereKey($task->id)->exists())->toBeFalse()
        ->and(Task::query()->withTrashed()->whereKey($task->id)->exists())->toBeTrue();
});

it('promotes the direct subtasks instead of deleting them', function (): void {
    [$parent, $actor] = taskEditableBy();
    $child = Task::factory()->childOf($parent)->create();

    app(DeleteTask::class)->handle($parent, $actor);

    // A subtask can be assigned to somebody else entirely; taking it away because its
    // parent was removed would delete work nobody asked to delete.
    expect($child->fresh()?->parent_id)->toBeNull()
        ->and($child->fresh()?->deleted_at)->toBeNull();
});

it('leaves a grandchild under its own parent', function (): void {
    [$parent, $actor] = taskEditableBy();
    $child = Task::factory()->childOf($parent)->create();
    $grandchild = Task::factory()->childOf($child)->create();

    app(DeleteTask::class)->handle($parent, $actor);

    expect($grandchild->fresh()?->parent_id)->toBe($child->id);
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
