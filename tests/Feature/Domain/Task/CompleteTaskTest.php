<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\CompleteTask;
use App\Domain\Task\Events\TaskCompleted;
use App\Domain\Task\Events\TaskReopened;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Event;

it('records who completed it and when', function (): void {
    [$task, $actor] = taskEditableBy();

    app(CompleteTask::class)->complete($task, $actor);

    $fresh = $task->fresh();

    expect($fresh?->isCompleted())->toBeTrue()
        ->and($fresh?->completed_by)->toBe($actor->id)
        ->and($fresh?->completed_at)->not->toBeNull();
});

it('reopens by clearing both columns', function (): void {
    [$task, $actor] = taskEditableBy();
    app(CompleteTask::class)->complete($task, $actor);

    app(CompleteTask::class)->reopen($task->refresh(), $actor);

    $fresh = $task->fresh();

    // Leaving completed_by behind would make a reopened task look done to anything that
    // reads the column instead of the timestamp.
    expect($fresh?->isCompleted())->toBeFalse()
        ->and($fresh?->completed_by)->toBeNull();
});

it('stays quiet when nothing changes', function (): void {
    [$task, $actor] = taskEditableBy();
    Event::fake();

    app(CompleteTask::class)->reopen($task, $actor);
    Event::assertNotDispatched(TaskReopened::class);

    app(CompleteTask::class)->complete($task, $actor);
    app(CompleteTask::class)->complete($task->refresh(), $actor);
    Event::assertDispatchedTimes(TaskCompleted::class, 1);
});

it('announces both directions', function (): void {
    [$task, $actor] = taskEditableBy();
    Event::fake();

    app(CompleteTask::class)->complete($task, $actor);
    Event::assertDispatched(TaskCompleted::class, fn (TaskCompleted $event): bool => $event->taskId === $task->id
        && $event->completedById === $actor->id);

    app(CompleteTask::class)->reopen($task->refresh(), $actor);
    Event::assertDispatched(TaskReopened::class, fn (TaskReopened $event): bool => $event->taskId === $task->id);
});

it('never consults a section to decide what completion means', function (): void {
    [$task, $actor] = taskEditableBy();
    $project = Project::factory()->in($task->workspace)->create();
    Section::factory()->in($project)->create(['name' => 'Done']);

    // A column called "Done" is a name somebody chose (ADR-0004). Nothing here reads it,
    // and a project that renames it must not reopen anybody's work.
    expect($task->fresh()?->isCompleted())->toBeFalse();

    app(CompleteTask::class)->complete($task, $actor);

    expect($task->fresh()?->isCompleted())->toBeTrue()
        ->and($project->sections()->count())->toBe(1);
});

it('leaves subtasks alone', function (): void {
    [$parent, $actor] = taskEditableBy();
    $child = Task::factory()->childOf($parent)->create();

    app(CompleteTask::class)->complete($parent, $actor);

    // Whether completing a parent should close its children is a product decision nobody
    // has made; doing nothing is the reversible half of it.
    expect($child->fresh()?->isCompleted())->toBeFalse();
});

it('refuses an actor without the task.update capability', function (): void {
    [$task, $guest] = taskEditableBy(WorkspaceRole::Guest);

    expect(fn (): Task => app(CompleteTask::class)->complete($task, $guest))
        ->toThrow(TaskException::class, 'permission to change');

    expect($task->fresh()?->isCompleted())->toBeFalse();
});

it('refuses somebody from another workspace in both directions', function (): void {
    [$task, $actor] = taskEditableBy();
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);
    app(CompleteTask::class)->complete($task, $actor);

    expect(fn (): Task => app(CompleteTask::class)->reopen($task->refresh(), $outsider))
        ->toThrow(TaskException::class);

    expect($task->fresh()?->isCompleted())->toBeTrue();
});
