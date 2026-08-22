<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Policies\TaskPolicy;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * @return array{Task, User}
 */
function taskFor(WorkspaceRole $role, WorkspaceMembershipStatus $status = WorkspaceMembershipStatus::Active): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role, $status);

    return [Task::factory()->in($workspace)->create(), $actor];
}

it('is the policy the gate resolves for a task', function (): void {
    expect(Gate::getPolicyFor(Task::class))->toBeInstanceOf(TaskPolicy::class);
});

it('answers each role the same way at every ability', function (WorkspaceRole $role, string $ability, bool $allowed): void {
    [$task, $actor] = taskFor($role);

    expect(Gate::forUser($actor)->allows($ability, $task))->toBe($allowed);
})->with([
    'owner views' => [WorkspaceRole::Owner, 'view', true],
    'owner updates' => [WorkspaceRole::Owner, 'update', true],
    'owner completes' => [WorkspaceRole::Owner, 'complete', true],
    'owner assigns' => [WorkspaceRole::Owner, 'assign', true],
    'owner deletes' => [WorkspaceRole::Owner, 'delete', true],

    'admin views' => [WorkspaceRole::Admin, 'view', true],
    'admin updates' => [WorkspaceRole::Admin, 'update', true],
    'admin assigns' => [WorkspaceRole::Admin, 'assign', true],
    'admin deletes' => [WorkspaceRole::Admin, 'delete', true],

    'member views' => [WorkspaceRole::Member, 'view', true],
    'member updates' => [WorkspaceRole::Member, 'update', true],
    'member completes' => [WorkspaceRole::Member, 'complete', true],
    'member assigns' => [WorkspaceRole::Member, 'assign', true],
    'member deletes' => [WorkspaceRole::Member, 'delete', true],

    // A guest reaches only what they were explicitly given, and until Phase 070 attaches
    // tasks to projects there is nothing that could have been given to them.
    'guest views' => [WorkspaceRole::Guest, 'view', false],
    'guest updates' => [WorkspaceRole::Guest, 'update', false],
    'guest completes' => [WorkspaceRole::Guest, 'complete', false],
    'guest assigns' => [WorkspaceRole::Guest, 'assign', false],
    'guest deletes' => [WorkspaceRole::Guest, 'delete', false],
]);

it('denies every ability while the membership is not active', function (WorkspaceMembershipStatus $status, string $ability): void {
    [$task, $actor] = taskFor(WorkspaceRole::Owner, $status);

    expect(Gate::forUser($actor)->allows($ability, $task))->toBeFalse();
})->with([
    'invited' => WorkspaceMembershipStatus::Invited,
    'declined' => WorkspaceMembershipStatus::Declined,
    'revoked' => WorkspaceMembershipStatus::Revoked,
    'expired' => WorkspaceMembershipStatus::Expired,
])->with(['view', 'update', 'complete', 'assign', 'delete']);

it('denies every ability to somebody from another workspace', function (string $ability): void {
    [$task] = taskFor(WorkspaceRole::Owner);
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    expect(Gate::forUser($outsider)->allows($ability, $task))->toBeFalse();
})->with(['view', 'update', 'complete', 'assign', 'delete']);

it('answers the same way for the Actions and the gate', function (): void {
    [$task, $member] = taskFor(WorkspaceRole::Member);

    // The Actions ask the membership row directly; the policy asks it through the same
    // method. A difference between the two is how a UI offers a button the server refuses.
    expect(Gate::forUser($member)->allows('update', $task))
        ->toBe($task->workspace->membershipFor($member)?->allows(Capability::TaskUpdate) === true);
});

it('lets a guest read a task that appears in a project they were given', function (): void {
    [$task, $guest] = taskFor(WorkspaceRole::Guest);
    $project = Project::factory()->in($task->workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    // The question TASK-060-012 left open, answered in TASK-070-017: a guest reaches a task
    // exactly when it appears in a project they hold (ADR-0003 with ADR-0006).
    expect(Gate::forUser($guest)->allows('view', $task))->toBeTrue();
});

it('keeps a guest out of a task in a project they were not given', function (): void {
    [$task, $guest] = taskFor(WorkspaceRole::Guest);
    $project = Project::factory()->in($task->workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    TaskProjectMembership::factory()->placing($task, $project)->create();

    // Workspace visibility never reaches a guest, so neither does a card on that board.
    expect(Gate::forUser($guest)->allows('view', $task))->toBeFalse();
});

it('keeps a member out of a task that lives only in a private project they are not in', function (): void {
    [$task, $member] = taskFor(WorkspaceRole::Member);
    $private = Project::factory()->in($task->workspace)->create(['visibility' => ProjectVisibility::Private]);
    TaskProjectMembership::factory()->placing($task, $private)->create();

    // Being in the workspace is not being in the room. Without this, a private project's
    // cards were readable — and editable — through the task endpoints (ADR-0006).
    expect(Gate::forUser($member)->allows('view', $task))->toBeFalse()
        ->and(Gate::forUser($member)->allows('update', $task))->toBeFalse()
        ->and(Gate::forUser($member)->allows('delete', $task))->toBeFalse();
});

it('lets a member read a task that is also on a board they can open', function (): void {
    [$task, $member] = taskFor(WorkspaceRole::Member);
    $private = Project::factory()->in($task->workspace)->create(['visibility' => ProjectVisibility::Private]);
    $open = Project::factory()->in($task->workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    TaskProjectMembership::factory()->placing($task, $private)->create();
    TaskProjectMembership::factory()->placing($task, $open)->create();

    // One task, two boards (ADR-0003): reaching either is reaching the task.
    expect(Gate::forUser($member)->allows('view', $task))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $task))->toBeTrue();
});

it('lets a member read a task that is on no board at all', function (): void {
    [$task, $member] = taskFor(WorkspaceRole::Member);

    // The inbox case: a quick capture nobody has filed yet is workspace work.
    expect(Gate::forUser($member)->allows('view', $task))->toBeTrue()
        ->and($task->placements()->count())->toBe(0);
});

it('reads an archived board as read-only rather than hidden', function (): void {
    [$task, $member] = taskFor(WorkspaceRole::Member);
    $archived = Project::factory()->in($task->workspace)->create([
        'visibility' => ProjectVisibility::Private,
        'archived_at' => now(),
    ]);
    ProjectMembership::factory()->in($archived)->forUser($member)->withAccess(ProjectAccessLevel::Editor)->create();
    TaskProjectMembership::factory()->placing($task, $archived)->create();

    expect(Gate::forUser($member)->allows('view', $task))->toBeTrue();
});
