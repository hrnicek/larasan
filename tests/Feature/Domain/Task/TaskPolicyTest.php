<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Capability;
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
