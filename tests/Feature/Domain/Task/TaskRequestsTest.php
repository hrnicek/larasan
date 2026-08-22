<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Data\CreateTaskData;
use App\Domain\Task\Data\UpdateTaskData;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Requests\Task\AssignTaskRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The endpoints arrive with TASK-060-017. Probe routes assert the requests for what they
 * are — validation and authorization — before a controller exists to confuse a failure
 * with a routing one.
 */
beforeEach(function (): void {
    Route::middleware('web')->post('task-probe/{workspace}', function (StoreTaskRequest $request) {
        $data = CreateTaskData::fromRequest($request);

        return response()->json([
            'title' => $data->title,
            'priority' => $data->priority->value,
            'parent_id' => $data->parentId,
            'assignee_id' => $data->assigneeId,
        ]);
    });

    Route::middleware('web')->put('task-probe/{task}', function (UpdateTaskRequest $request, Task $task) {
        $data = UpdateTaskData::fromRequest($request);

        return response()->json(['title' => $data->title, 'parent_id' => $data->parentId]);
    });

    Route::middleware('web')->put('task-probe/{task}/assignee', fn (AssignTaskRequest $request, Task $task) => response()->json([
        'assignee_id' => $request->integer('assignee_id') ?: null,
    ]));
});

/**
 * @return array{Workspace, User}
 */
function workspaceForTaskRequests(WorkspaceRole $role = WorkspaceRole::Member): array
{
    $workspace = Workspace::factory()->create(['slug' => 'acme']);

    return [$workspace, memberOf($workspace, $role)];
}

it('builds the data object from a valid create request', function (): void {
    [, $actor] = workspaceForTaskRequests();

    $this->actingAs($actor)
        ->postJson('task-probe/acme', ['title' => 'Write it down'])
        ->assertOk()
        ->assertJson(['title' => 'Write it down', 'priority' => TaskPriority::Medium->value, 'parent_id' => null]);
});

it('refuses creation to a guest', function (): void {
    [, $guest] = workspaceForTaskRequests(WorkspaceRole::Guest);

    $this->actingAs($guest)->postJson('task-probe/acme', ['title' => 'Write it down'])->assertForbidden();
});

it('rejects a create request without a title', function (): void {
    [, $actor] = workspaceForTaskRequests();

    $this->actingAs($actor)->postJson('task-probe/acme', [])->assertJsonValidationErrorFor('title');
});

it('rejects a priority outside the enum', function (): void {
    [, $actor] = workspaceForTaskRequests();

    $this->actingAs($actor)
        ->postJson('task-probe/acme', ['title' => 'Write it down', 'priority' => 'whenever'])
        ->assertJsonValidationErrorFor('priority');
});

it('rejects a parent from another workspace', function (): void {
    [, $actor] = workspaceForTaskRequests();
    $foreign = Task::factory()->create();

    $this->actingAs($actor)
        ->postJson('task-probe/acme', ['title' => 'Child', 'parent_id' => $foreign->id])
        ->assertJsonValidationErrorFor('parent_id');
});

it('rejects a soft-deleted task as a parent', function (): void {
    [$workspace, $actor] = workspaceForTaskRequests();
    $parent = Task::factory()->in($workspace)->create();
    $parent->delete();

    $this->actingAs($actor)
        ->postJson('task-probe/acme', ['title' => 'Child', 'parent_id' => $parent->id])
        ->assertJsonValidationErrorFor('parent_id');
});

it('accepts a parent in the same workspace', function (): void {
    [$workspace, $actor] = workspaceForTaskRequests();
    $parent = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->postJson('task-probe/acme', ['title' => 'Child', 'parent_id' => $parent->id])
        ->assertOk()
        ->assertJson(['parent_id' => $parent->id]);
});

it('rejects an assignee who is not an active member', function (WorkspaceMembershipStatus $status): void {
    [$workspace, $actor] = workspaceForTaskRequests();
    $candidate = memberOf($workspace, WorkspaceRole::Member, $status);

    $this->actingAs($actor)
        ->postJson('task-probe/acme', ['title' => 'Write it down', 'assignee_id' => $candidate->id])
        ->assertJsonValidationErrorFor('assignee_id');
})->with([
    'invited' => WorkspaceMembershipStatus::Invited,
    'revoked' => WorkspaceMembershipStatus::Revoked,
]);

it('rejects an account from another workspace as assignee', function (): void {
    [, $actor] = workspaceForTaskRequests();
    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    $this->actingAs($actor)
        ->postJson('task-probe/acme', ['title' => 'Write it down', 'assignee_id' => $stranger->id])
        ->assertJsonValidationErrorFor('assignee_id');
});

it('lets a member update a task and refuses a guest', function (): void {
    [$workspace, $actor] = workspaceForTaskRequests();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->putJson("task-probe/{$task->id}", ['title' => 'Renamed'])
        ->assertOk()
        ->assertJson(['title' => 'Renamed']);

    $guest = memberOf($workspace, WorkspaceRole::Guest);

    $this->actingAs($guest)->putJson("task-probe/{$task->id}", ['title' => 'Renamed'])->assertForbidden();
});

it('rejects a task as its own parent', function (): void {
    [$workspace, $actor] = workspaceForTaskRequests();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->putJson("task-probe/{$task->id}", ['title' => 'Renamed', 'parent_id' => $task->id])
        ->assertJsonValidationErrorFor('parent_id');
});

it('accepts an assignment to a member and null to unassign', function (): void {
    [$workspace, $actor] = workspaceForTaskRequests();
    $task = Task::factory()->in($workspace)->create();
    $assignee = memberOf($workspace, WorkspaceRole::Member);

    $this->actingAs($actor)
        ->putJson("task-probe/{$task->id}/assignee", ['assignee_id' => $assignee->id])
        ->assertOk()
        ->assertJson(['assignee_id' => $assignee->id]);

    $this->actingAs($actor)
        ->putJson("task-probe/{$task->id}/assignee", ['assignee_id' => null])
        ->assertOk()
        ->assertJson(['assignee_id' => null]);
});

it('rejects an assignment to somebody outside the workspace', function (): void {
    [$workspace, $actor] = workspaceForTaskRequests();
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->putJson("task-probe/{$task->id}/assignee", ['assignee_id' => User::factory()->create()->id])
        ->assertJsonValidationErrorFor('assignee_id');
});
