<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{Task, User, Project}
 */
function matrixDetailTask(
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    ProjectVisibility $visibility = ProjectVisibility::Workspace,
): array {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create(['visibility' => $visibility]);

    if ($access !== null) {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();
    }

    $task = Task::factory()->in($workspace)->create(['title' => 'Untouched']);
    TaskProjectMembership::factory()->placing($task, $project)->create();

    return [$task, $actor, $project];
}

/**
 * @return array{string, string, array<string, mixed>}
 */
function matrixDetailOperation(string $operation, Task $task): array
{
    return match ($operation) {
        'read' => ['get', route('tasks.show', $task), []],
        'rename' => ['put', route('tasks.update', $task), ['title' => 'Renamed']],
        'complete' => ['put', route('tasks.complete', $task), []],
        'follow' => ['post', route('tasks.follow', $task), []],
        'delete' => ['delete', route('tasks.destroy', $task), []],
        default => throw new InvalidArgumentException("Unknown matrix operation [{$operation}]."),
    };
}

it('answers each role and access level the same way at the detail', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    string $operation,
    string $outcome,
): void {
    [$task, $actor] = matrixDetailTask($role, $access);

    [$method, $url, $payload] = matrixDetailOperation($operation, $task);

    $response = $this->actingAs($actor)->from(route('dashboard'))->{$method}($url, $payload);

    match ($outcome) {
        'allowed' => expect($response->status())->toBeIn([200, 302]),
        'forbidden' => $response->assertForbidden(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };

    if ($outcome !== 'allowed') {
        expect($task->fresh()?->title)->toBe('Untouched');
    }
})->with([
    'owner reads' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'read', 'allowed'],
    'owner renames' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'rename', 'allowed'],
    'owner completes' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'complete', 'allowed'],
    'owner follows' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'follow', 'allowed'],
    'owner deletes' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'delete', 'allowed'],

    'admin renames' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'rename', 'allowed'],
    'admin deletes' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'delete', 'allowed'],

    'member renames' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'rename', 'allowed'],
    // Following only subscribes to what the actor can already read, so a Viewer may follow. See ADR-0006.
    'member completes' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'complete', 'forbidden'],
    'member follows' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'follow', 'allowed'],
    'member with no project membership reads' => [WorkspaceRole::Member, null, 'read', 'allowed'],

    // Guests hold neither `task.update` nor `task.delete`, whatever their project access. See ADR-0010.
    'guest given the project reads' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'read', 'allowed'],
    'guest given the project follows' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'follow', 'allowed'],
    'guest given the project renames' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'rename', 'forbidden'],
    'guest given the project completes' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'complete', 'forbidden'],
    'guest given the project deletes' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'delete', 'forbidden'],
    'guest with no project membership reads' => [WorkspaceRole::Guest, null, 'read', 'forbidden'],
]);

it('refuses a task that lives only in a private project the actor was not given', function (
    WorkspaceRole $role,
    string $operation,
): void {
    [$task, $actor] = matrixDetailTask($role, null, ProjectVisibility::Private);

    [$method, $url, $payload] = matrixDetailOperation($operation, $task);

    // 403, not 404: the task belongs to the actor's own workspace.
    $this->actingAs($actor)->{$method}($url, $payload)->assertForbidden();

    expect($task->fresh()?->title)->toBe('Untouched');
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
])->with([
    'read' => ['read'],
    'rename' => ['rename'],
    'follow' => ['follow'],
]);

it('hides a task in another workspace behind a 404 for every role', function (WorkspaceRole $role): void {
    [$task] = matrixDetailTask(WorkspaceRole::Owner, ProjectAccessLevel::Owner);
    $stranger = memberOf(Workspace::factory()->create(), $role);

    $this->actingAs($stranger)->get(route('tasks.show', $task))->assertNotFound();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('sends the flags the detail renders from, and they match what the actions answer', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    bool $update,
    bool $delete,
    bool $comment,
): void {
    [$task, $actor] = matrixDetailTask($role, $access);

    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('can.update', $update)
            ->where('can.delete', $delete)
            ->where('can.comment', $comment));
})->with([
    'owner as project owner' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, true, true, true],
    'member as editor' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, true, true, true],
    'member as viewer' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, false, false, false],
    'guest as editor' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, false, false, true],
]);
