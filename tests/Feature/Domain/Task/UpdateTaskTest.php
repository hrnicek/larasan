<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\UpdateTask;
use App\Domain\Task\Ancestry\ParentChain;
use App\Domain\Task\Data\UpdateTaskData;
use App\Domain\Task\Events\TaskUpdated;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

/**
 * @return array{Task, User}
 */
function taskEditableBy(WorkspaceRole $role = WorkspaceRole::Member): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);

    return [Task::factory()->in($workspace)->create(['title' => 'Untouched']), $actor];
}

it('updates the fields it owns', function (): void {
    [$task, $actor] = taskEditableBy();
    $due = CarbonImmutable::parse('2026-07-01 08:00:00');

    app(UpdateTask::class)->handle($task, $actor, new UpdateTaskData(
        title: 'Renamed',
        description: 'Now with a reason',
        priority: TaskPriority::High,
        dueAt: $due,
    ));

    $fresh = $task->fresh();

    expect($fresh?->title)->toBe('Renamed')
        ->and($fresh?->description)->toBe('Now with a reason')
        ->and($fresh?->priority)->toBe(TaskPriority::High)
        ->and($fresh?->due_at?->equalTo($due))->toBeTrue();
});

it('clears a nullable field the caller emptied and keeps what null cannot describe', function (): void {
    [$task, $actor] = taskEditableBy();
    $task->forceFill([
        'description' => 'Old',
        'due_at' => CarbonImmutable::parse('2026-07-01 08:00:00'),
        'priority' => TaskPriority::Urgent->value,
    ])->save();

    app(UpdateTask::class)->handle($task, $actor, new UpdateTaskData(title: 'Renamed'));

    $fresh = $task->fresh();

    expect($fresh?->description)->toBeNull()
        ->and($fresh?->due_at)->toBeNull()
        // Priority is not nullable, so a null says nothing about it.
        ->and($fresh?->priority)->toBe(TaskPriority::Urgent);
});

it('cannot reach completion, ownership or authorship', function (): void {
    [$task, $actor] = taskEditableBy();
    $before = $task->only(['workspace_id', 'created_by', 'completed_at', 'completed_by', 'assignee_id']);

    app(UpdateTask::class)->handle($task, $actor, new UpdateTaskData(title: 'Renamed'));

    expect($task->fresh()?->only(['workspace_id', 'created_by', 'completed_at', 'completed_by', 'assignee_id']))
        ->toBe($before);
});

it('announces only what changed and stays quiet on a no-op', function (): void {
    [$task, $actor] = taskEditableBy();
    Event::fake();

    app(UpdateTask::class)->handle($task, $actor, new UpdateTaskData(title: 'Untouched'));
    Event::assertNotDispatched(TaskUpdated::class);

    app(UpdateTask::class)->handle($task, $actor, new UpdateTaskData(title: 'Renamed'));
    Event::assertDispatched(TaskUpdated::class, fn (TaskUpdated $event): bool => $event->changed === ['title']
        && $event->workspaceId === $task->workspace_id);
});

it('moves a task under a parent in the same workspace', function (): void {
    [$task, $actor] = taskEditableBy();
    $parent = Task::factory()->in($task->workspace)->create();

    app(UpdateTask::class)->handle($task, $actor, new UpdateTaskData(title: 'Untouched', parentId: $parent->id));

    expect($task->fresh()?->parent_id)->toBe($parent->id);
});

it('refuses a parent from another workspace', function (): void {
    [$task, $actor] = taskEditableBy();
    $foreign = Task::factory()->create();

    expect(fn (): Task => app(UpdateTask::class)->handle($task, $actor, new UpdateTaskData(title: 'Untouched', parentId: $foreign->id)))
        ->toThrow(TaskException::class, 'same workspace');

    expect($task->fresh()?->parent_id)->toBeNull();
});

it('refuses to make a task its own parent', function (): void {
    [$task, $actor] = taskEditableBy();

    expect(fn (): Task => app(UpdateTask::class)->handle($task, $actor, new UpdateTaskData(title: 'Untouched', parentId: $task->id)))
        ->toThrow(TaskException::class, 'subtask of itself');
});

it('detaches a subtask when no parent is given', function (): void {
    [$task, $actor] = taskEditableBy();
    $parent = Task::factory()->in($task->workspace)->create();
    $task->forceFill(['parent_id' => $parent->id])->save();

    app(UpdateTask::class)->handle($task->fresh(), $actor, new UpdateTaskData(title: 'Untouched'));

    expect($task->fresh()?->parent_id)->toBeNull();
});

it('refuses a parent that is one of the task s own subtasks', function (): void {
    [$task, $actor] = taskEditableBy();
    $child = Task::factory()->childOf($task)->create();

    expect(fn (): Task => app(UpdateTask::class)->handle($task, $actor, new UpdateTaskData(title: 'Untouched', parentId: $child->id)))
        ->toThrow(TaskException::class, 'through its own subtasks');

    expect($task->fresh()?->parent_id)->toBeNull()
        ->and($child->fresh()?->parent_id)->toBe($task->id);
});

it('refuses a loop several levels down', function (): void {
    [$root, $actor] = taskEditableBy();
    $child = Task::factory()->childOf($root)->create();
    $grandchild = Task::factory()->childOf($child)->create();

    expect(fn (): Task => app(UpdateTask::class)->handle($root, $actor, new UpdateTaskData(title: 'Untouched', parentId: $grandchild->id)))
        ->toThrow(TaskException::class, 'through its own subtasks');
});

it('allows a sibling to become a parent', function (): void {
    [$parent, $actor] = taskEditableBy();
    $first = Task::factory()->childOf($parent)->create();
    $second = Task::factory()->childOf($parent)->create();

    app(UpdateTask::class)->handle($second, $actor, new UpdateTaskData(title: $second->title, parentId: $first->id));

    expect($second->fresh()?->parent_id)->toBe($first->id);
});

it('refuses a chain deeper than the stated maximum', function (): void {
    [$root, $actor] = taskEditableBy();

    $deepest = $root;

    foreach (range(2, ParentChain::MAX_DEPTH) as $level) {
        $deepest = Task::factory()->childOf($deepest)->create();
    }

    $orphan = Task::factory()->in($root->workspace)->create();

    // The limit is a stated rule, not a discovery: without one a chain grows until whatever
    // walks it becomes the slowest page in the product.
    expect(fn (): Task => app(UpdateTask::class)->handle($orphan, $actor, new UpdateTaskData(title: $orphan->title, parentId: $deepest->id)))
        ->toThrow(TaskException::class, 'nested that deeply');
});

it('refuses an actor without the task.update capability', function (): void {
    [$task, $guest] = taskEditableBy(WorkspaceRole::Guest);

    expect(fn (): Task => app(UpdateTask::class)->handle($task, $guest, new UpdateTaskData(title: 'Renamed')))
        ->toThrow(TaskException::class, 'permission to change');

    expect($task->fresh()?->title)->toBe('Untouched');
});

it('refuses somebody from another workspace', function (): void {
    [$task] = taskEditableBy();
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    expect(fn (): Task => app(UpdateTask::class)->handle($task, $outsider, new UpdateTaskData(title: 'Renamed')))
        ->toThrow(TaskException::class);

    expect($task->fresh()?->title)->toBe('Untouched');
});
