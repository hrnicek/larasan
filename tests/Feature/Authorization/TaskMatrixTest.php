<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * @return array{Task, User}
 */
function matrixTask(WorkspaceRole $role, WorkspaceMembershipStatus $status = WorkspaceMembershipStatus::Active): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role, $status);

    return [Task::factory()->in($workspace)->create(['title' => 'Untouched']), $actor];
}

/**
 * @return array{string, string, array<string, mixed>}
 */
function matrixTaskOperation(string $operation, Task $task): array
{
    return match ($operation) {
        'create' => ['post', route('tasks.store'), ['title' => 'Added']],
        'update' => ['put', route('tasks.update', $task), ['title' => 'Renamed']],
        'complete' => ['put', route('tasks.complete', $task), []],
        'assign' => ['put', route('tasks.assign', $task), []],
        'collaborate' => ['post', route('tasks.collaborators.store', $task), ['user_id' => memberOf($task->workspace)->id]],
        'delete' => ['delete', route('tasks.destroy', $task), []],
        default => throw new InvalidArgumentException("Unknown matrix operation [{$operation}]."),
    };
}

it('answers each role the same way at every task endpoint', function (
    WorkspaceRole $role,
    string $operation,
    string $outcome,
): void {
    [$task, $actor] = matrixTask($role);

    [$method, $url, $payload] = matrixTaskOperation($operation, $task);

    $response = $this->actingAs($actor)->{$method}($url, $payload);

    match ($outcome) {
        'allowed' => expect($response->status())->toBeIn([200, 302]),
        'forbidden' => $response->assertForbidden(),
        'missing' => $response->assertNotFound(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };

    if ($outcome !== 'allowed') {
        expect($task->fresh()?->title)->toBe('Untouched')
            ->and($task->fresh()?->isCompleted())->toBeFalse()
            ->and($task->collaborations()->count())->toBe(0);
    }
})->with([
    'owner create' => [WorkspaceRole::Owner, 'create', 'allowed'],
    'owner update' => [WorkspaceRole::Owner, 'update', 'allowed'],
    'owner complete' => [WorkspaceRole::Owner, 'complete', 'allowed'],
    'owner assign' => [WorkspaceRole::Owner, 'assign', 'allowed'],
    'owner collaborate' => [WorkspaceRole::Owner, 'collaborate', 'allowed'],
    'owner delete' => [WorkspaceRole::Owner, 'delete', 'allowed'],
    'admin create' => [WorkspaceRole::Admin, 'create', 'allowed'],
    'admin update' => [WorkspaceRole::Admin, 'update', 'allowed'],
    'admin complete' => [WorkspaceRole::Admin, 'complete', 'allowed'],
    'admin assign' => [WorkspaceRole::Admin, 'assign', 'allowed'],
    'admin collaborate' => [WorkspaceRole::Admin, 'collaborate', 'allowed'],
    'admin delete' => [WorkspaceRole::Admin, 'delete', 'allowed'],
    'member create' => [WorkspaceRole::Member, 'create', 'allowed'],
    'member update' => [WorkspaceRole::Member, 'update', 'allowed'],
    'member complete' => [WorkspaceRole::Member, 'complete', 'allowed'],
    'member assign' => [WorkspaceRole::Member, 'assign', 'allowed'],
    'member collaborate' => [WorkspaceRole::Member, 'collaborate', 'allowed'],
    'member delete' => [WorkspaceRole::Member, 'delete', 'allowed'],
    'guest create' => [WorkspaceRole::Guest, 'create', 'forbidden'],
    'guest update' => [WorkspaceRole::Guest, 'update', 'forbidden'],
    'guest complete' => [WorkspaceRole::Guest, 'complete', 'forbidden'],
    'guest assign' => [WorkspaceRole::Guest, 'assign', 'forbidden'],
    'guest collaborate' => [WorkspaceRole::Guest, 'collaborate', 'forbidden'],
    'guest delete' => [WorkspaceRole::Guest, 'delete', 'forbidden'],
]);

it('refuses every operation while the membership is not active', function (
    WorkspaceMembershipStatus $status,
    string $operation,
): void {
    [$task, $actor] = matrixTask(WorkspaceRole::Owner, $status);

    [$method, $url, $payload] = matrixTaskOperation($operation, $task);

    $response = $this->actingAs($actor)->{$method}($url, $payload);

    // Creating is 403 because authorization runs first; the rest are 404 because no workspace resolves.
    expect($response->status())->toBeIn([403, 404]);

    expect($task->fresh()?->title)->toBe('Untouched')
        ->and(Task::query()->count())->toBe(1);
})->with([
    'invited' => WorkspaceMembershipStatus::Invited,
    'declined' => WorkspaceMembershipStatus::Declined,
    'revoked' => WorkspaceMembershipStatus::Revoked,
    'expired' => WorkspaceMembershipStatus::Expired,
])->with(['create', 'update', 'complete', 'assign', 'collaborate', 'delete']);

it('hides another workspace s task at every endpoint that names one', function (string $operation): void {
    [$task] = matrixTask(WorkspaceRole::Owner);
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    [$method, $url, $payload] = matrixTaskOperation($operation, $task);

    $this->actingAs($outsider)->{$method}($url, $payload)->assertNotFound();

    expect($task->fresh()?->title)->toBe('Untouched');
})->with(['update', 'complete', 'assign', 'collaborate', 'delete']);

it('redirects an unauthenticated visitor from every task endpoint', function (string $operation): void {
    [$task] = matrixTask(WorkspaceRole::Owner);

    [$method, $url, $payload] = matrixTaskOperation($operation, $task);

    $this->{$method}($url, $payload)->assertRedirect(route('login'));

    expect($task->fresh()?->title)->toBe('Untouched');
})->with(['create', 'update', 'complete', 'assign', 'delete']);

it('refuses a payload that reaches across the tenant boundary', function (): void {
    [$task, $actor] = matrixTask(WorkspaceRole::Owner);
    $foreignTask = Task::factory()->create();
    $stranger = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    // Foreign ids fail validation, which keeps the domain exception off a reachable path.
    $this->actingAs($actor)
        ->put(route('tasks.update', $task), ['title' => 'Renamed', 'parent_id' => $foreignTask->id])
        ->assertSessionHasErrors('parent_id');

    $this->actingAs($actor)
        ->put(route('tasks.assign', $task), ['assignee_id' => $stranger->id])
        ->assertSessionHasErrors('assignee_id');

    expect($task->fresh()?->parent_id)->toBeNull()
        ->and($task->fresh()?->assignee_id)->toBeNull();
});
