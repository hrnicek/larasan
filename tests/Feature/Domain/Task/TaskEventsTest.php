<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Events\TaskCompleted;
use App\Domain\Task\Events\TaskCreated;
use App\Domain\Task\Events\TaskDeleted;
use App\Domain\Task\Events\TaskReopened;
use App\Domain\Task\Events\TaskUpdated;
use App\Domain\Task\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * @return array<string, object>
 */
function taskEventSamples(): array
{
    return [
        'created' => new TaskCreated('01a00000-0000-7000-8000-000000000001', '01a00000-0000-7000-8000-000000000002', 1),
        'updated' => new TaskUpdated('01a00000-0000-7000-8000-000000000001', '01a00000-0000-7000-8000-000000000002', ['title'], 1),
        'completed' => new TaskCompleted('01a00000-0000-7000-8000-000000000001', '01a00000-0000-7000-8000-000000000002', 1),
        'reopened' => new TaskReopened('01a00000-0000-7000-8000-000000000001', '01a00000-0000-7000-8000-000000000002', 1),
        'assigned' => new TaskAssigned('01a00000-0000-7000-8000-000000000001', '01a00000-0000-7000-8000-000000000002', 1, 2),
        'deleted' => new TaskDeleted('01a00000-0000-7000-8000-000000000001', '01a00000-0000-7000-8000-000000000002', 1),
    ];
}

it('announces every task operation through the endpoints', function (): void {
    [$task, $actor] = taskEditableBy();
    $assignee = memberOf($task->workspace, WorkspaceRole::Member);
    Event::fake();

    $this->actingAs($actor)->post(route('tasks.store'), ['title' => 'Created through HTTP']);
    $this->actingAs($actor)->put(route('tasks.update', $task), ['title' => 'Renamed']);
    $this->actingAs($actor)->put(route('tasks.complete', $task));
    $this->actingAs($actor)->delete(route('tasks.reopen', $task));
    $this->actingAs($actor)->put(route('tasks.assign', $task), ['assignee_id' => $assignee->id]);
    $this->actingAs($actor)->delete(route('tasks.destroy', $task));

    Event::assertDispatched(TaskCreated::class);
    Event::assertDispatched(TaskUpdated::class);
    Event::assertDispatched(TaskCompleted::class);
    Event::assertDispatched(TaskReopened::class);
    Event::assertDispatched(TaskAssigned::class);
    Event::assertDispatched(TaskDeleted::class);
});

it('carries ids rather than models', function (object $event): void {
    $properties = (new ReflectionObject($event))->getProperties();

    foreach ($properties as $property) {
        $type = $property->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

        // A queued listener would read a model as it is when the job runs, not when the event happened.
        expect($name === null || ! is_subclass_of($name, Model::class))->toBeTrue();
    }

    expect($properties)->not->toBeEmpty();
})->with(taskEventSamples(...));

it('is readonly, so a listener cannot rewrite what happened', function (object $event): void {
    expect((new ReflectionObject($event))->isReadOnly())->toBeTrue();
})->with(taskEventSamples(...));

it('names the workspace on every event, so a listener can scope without a lookup', function (object $event): void {
    $properties = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionObject($event))->getProperties(),
    );

    expect($properties)->toContain('taskId')
        ->and($properties)->toContain('workspaceId');
})->with(taskEventSamples(...));

it('says what changed, and who did it', function (): void {
    [$task, $actor] = taskEditableBy();
    Event::fake();

    $this->actingAs($actor)->put(route('tasks.update', $task), ['title' => 'Renamed']);

    Event::assertDispatched(TaskUpdated::class, fn (TaskUpdated $event): bool => $event->changed === ['title']);

    $this->actingAs($actor)->put(route('tasks.complete', $task));

    Event::assertDispatched(TaskCompleted::class, fn (TaskCompleted $event): bool => $event->completedById === $actor->id);
});

it('does not announce a change that did not happen', function (): void {
    [$task, $actor] = taskEditableBy();
    $task->forceFill(['title' => 'Same'])->save();
    Event::fake();

    $this->actingAs($actor)->put(route('tasks.update', $task), ['title' => 'Same']);

    Event::assertNotDispatched(TaskUpdated::class);
});

it('announces a deletion with the ids the task no longer has', function (): void {
    [$task, $actor] = taskEditableBy();
    $taskId = $task->id;
    $workspaceId = $task->workspace_id;
    Event::fake();

    $this->actingAs($actor)->delete(route('tasks.destroy', $task));

    Event::assertDispatched(TaskDeleted::class, fn (TaskDeleted $event): bool => $event->taskId === $taskId
        && $event->workspaceId === $workspaceId);

    expect(Task::query()->whereKey($taskId)->exists())->toBeFalse();
});
