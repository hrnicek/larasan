<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\CreateTask;
use App\Domain\Task\Ancestry\ParentChain;
use App\Domain\Task\Data\CreateTaskData;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Events\TaskCreated;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

function createTask(Workspace $workspace, User $creator, ?CreateTaskData $data = null): Task
{
    return app(CreateTask::class)->handle($workspace, $creator, $data ?? new CreateTaskData(title: 'Write the Action'));
}

it('creates a task owned by the workspace and by no project', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);

    $task = createTask($workspace, $creator);

    expect($task->workspace_id)->toBe($workspace->id)
        ->and($task->created_by)->toBe($creator->id)
        ->and($task->priority)->toBe(TaskPriority::Medium)
        ->and($task->isCompleted())->toBeFalse()
        ->and($task->parent_id)->toBeNull();
});

it('honours the attributes the caller supplied', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);
    $assignee = memberOf($workspace, WorkspaceRole::Member);
    $due = CarbonImmutable::parse('2026-06-01 17:00:00');

    $task = createTask($workspace, $creator, new CreateTaskData(
        title: 'Ship it',
        description: 'With the tests',
        priority: TaskPriority::Urgent,
        dueAt: $due,
        assigneeId: $assignee->id,
    ));

    expect($task->fresh()?->title)->toBe('Ship it')
        ->and($task->fresh()?->description)->toBe('With the tests')
        ->and($task->fresh()?->priority)->toBe(TaskPriority::Urgent)
        ->and($task->fresh()?->due_at?->equalTo($due))->toBeTrue()
        ->and($task->fresh()?->assignee_id)->toBe($assignee->id);
});

it('announces the task it created', function (): void {
    Event::fake();
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);

    $task = createTask($workspace, $creator);

    Event::assertDispatched(TaskCreated::class, fn (TaskCreated $event): bool => $event->taskId === $task->id
        && $event->workspaceId === $workspace->id
        && $event->createdById === $creator->id);
});

it('accepts a parent in the same workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);
    $parent = createTask($workspace, $creator, new CreateTaskData(title: 'Parent'));

    $child = createTask($workspace, $creator, new CreateTaskData(title: 'Child', parentId: $parent->id));

    expect($child->parent_id)->toBe($parent->id)
        ->and($parent->children()->pluck('id')->all())->toBe([$child->id]);
});

it('refuses a parent from another workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);
    $foreignParent = Task::factory()->create();

    expect(fn (): Task => createTask($workspace, $creator, new CreateTaskData(title: 'Child', parentId: $foreignParent->id)))
        ->toThrow(TaskException::class, 'same workspace');

    expect($workspace->tasks()->count())->toBe(0);
});

it('refuses a parent that does not exist', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);

    expect(fn (): Task => createTask($workspace, $creator, new CreateTaskData(title: 'Child', parentId: (string) Str::uuid7())))
        ->toThrow(TaskException::class);
});

it('refuses to create a subtask past the depth limit', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);

    $deepest = createTask($workspace, $creator, new CreateTaskData(title: 'Root'));

    foreach (range(2, ParentChain::MAX_DEPTH - 1) as $level) {
        $deepest = createTask($workspace, $creator, new CreateTaskData(title: "Level {$level}", parentId: $deepest->id));
    }

    $last = createTask($workspace, $creator, new CreateTaskData(title: 'Last legal', parentId: $deepest->id));

    expect(fn (): Task => createTask($workspace, $creator, new CreateTaskData(title: 'Too deep', parentId: $last->id)))
        ->toThrow(TaskException::class, 'nested that deeply');
});

it('refuses an assignee who is not an active member', function (WorkspaceMembershipStatus $status): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);
    $outsider = memberOf($workspace, WorkspaceRole::Member, $status);

    expect(fn (): Task => createTask($workspace, $creator, new CreateTaskData(title: 'Ship it', assigneeId: $outsider->id)))
        ->toThrow(TaskException::class, 'active member');

    expect($workspace->tasks()->count())->toBe(0);
})->with([
    'invited' => WorkspaceMembershipStatus::Invited,
    'revoked' => WorkspaceMembershipStatus::Revoked,
    'expired' => WorkspaceMembershipStatus::Expired,
]);

it('refuses an assignee from another workspace entirely', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);
    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    expect(fn (): Task => createTask($workspace, $creator, new CreateTaskData(title: 'Ship it', assigneeId: $stranger->id)))
        ->toThrow(TaskException::class, 'active member');
});

it('refuses an actor without the task.create capability', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    expect(fn (): Task => createTask($workspace, $guest))
        ->toThrow(TaskException::class, 'permission to create tasks');
});

it('refuses somebody from outside the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    expect(fn (): Task => createTask($workspace, $outsider))->toThrow(TaskException::class);

    expect($workspace->tasks()->count())->toBe(0);
});

it('refuses a member whose own membership is not active', function (): void {
    $workspace = Workspace::factory()->create();
    $suspended = memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked);

    expect(fn (): Task => createTask($workspace, $suspended))->toThrow(TaskException::class);
});

it('assigns through the same path as an assignment made later', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);
    $assignee = memberOf($workspace, WorkspaceRole::Member);
    Event::fake([TaskAssigned::class]);

    $task = createTask($workspace, $creator, new CreateTaskData(title: 'Ship it', assigneeId: $assignee->id));

    Event::assertDispatched(TaskAssigned::class, fn (TaskAssigned $event): bool => $event->taskId === $task->id
        && $event->assigneeId === $assignee->id
        && $event->assignedById === $creator->id);
});

it('makes the assignee of a new task follow it', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);
    $assignee = memberOf($workspace, WorkspaceRole::Member);

    $task = createTask($workspace, $creator, new CreateTaskData(title: 'Ship it', assigneeId: $assignee->id));

    expect($task->followers()->pluck('users.id')->all())->toContain($assignee->id);
});

it('refuses to hand a new task to a guest who could not open it', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    expect(fn (): Task => createTask($workspace, $creator, new CreateTaskData(title: 'Ship it', assigneeId: $guest->id)))
        ->toThrow(TaskException::class, 'That person cannot reach this task.');

    expect($workspace->tasks()->count())->toBe(0);
});
