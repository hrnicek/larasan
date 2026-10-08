<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Domain\Workspace\Policies\WorkspacePolicy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

it('is the policy the gate resolves for a workspace', function (): void {
    expect(Gate::getPolicyFor(Workspace::class))->toBeInstanceOf(WorkspacePolicy::class);
});

it('denies every ability to someone who is not a member', function (string $ability): void {
    $workspace = Workspace::factory()->create();
    $outsider = User::factory()->create();

    expect(Gate::forUser($outsider)->allows($ability, $workspace))->toBeFalse();
})->with(['view', 'update', 'delete', 'manageMembers', 'createProject']);

it('denies every ability while the membership is not active', function (string $ability): void {
    [$workspace, $user] = workspaceWith(WorkspaceRole::Owner, WorkspaceMembershipStatus::Revoked);

    expect(Gate::forUser($user)->allows($ability, $workspace))->toBeFalse();
})->with(['view', 'update', 'delete', 'manageMembers', 'createProject']);

it('lets an owner do everything', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $gate = Gate::forUser($owner);

    expect($gate->allows('view', $workspace))->toBeTrue()
        ->and($gate->allows('update', $workspace))->toBeTrue()
        ->and($gate->allows('delete', $workspace))->toBeTrue()
        ->and($gate->allows('manageMembers', $workspace))->toBeTrue();
});

it('stops an admin at deleting the workspace', function (): void {
    [$workspace, $admin] = workspaceWith(WorkspaceRole::Admin);
    $gate = Gate::forUser($admin);

    expect($gate->allows('update', $workspace))->toBeTrue()
        ->and($gate->allows('manageMembers', $workspace))->toBeTrue()
        ->and($gate->allows('delete', $workspace))->toBeFalse();
});

it('lets a member view and create projects but not administer', function (): void {
    [$workspace, $member] = workspaceWith(WorkspaceRole::Member);
    $gate = Gate::forUser($member);

    expect($gate->allows('view', $workspace))->toBeTrue()
        ->and($gate->allows('createProject', $workspace))->toBeTrue()
        ->and($gate->allows('update', $workspace))->toBeFalse()
        ->and($gate->allows('manageMembers', $workspace))->toBeFalse();
});

it('lets a guest view and nothing more', function (): void {
    [$workspace, $guest] = workspaceWith(WorkspaceRole::Guest);
    $gate = Gate::forUser($guest);

    expect($gate->allows('view', $workspace))->toBeTrue()
        ->and($gate->allows('createProject', $workspace))->toBeFalse()
        ->and($gate->allows('update', $workspace))->toBeFalse();
});

it('enforces the policy through an authorized endpoint, not only in isolation', function (): void {
    // Keyed by slug: the resolution middleware reads the workspace parameter as a slug.
    Route::middleware('web')->get('workspace-policy-probe/{workspace}', function (string $workspace) {
        $model = Workspace::query()->where('slug', $workspace)->firstOrFail();

        Gate::authorize('update', $model);

        return response()->noContent();
    });

    [$workspace, $member] = workspaceWith(WorkspaceRole::Member);
    [, $admin] = workspaceWith(WorkspaceRole::Admin);

    WorkspaceMembership::factory()->admin()->active()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $admin->id,
    ]);

    $this->actingAs($member)->get("workspace-policy-probe/{$workspace->slug}")->assertForbidden();
    $this->actingAs($admin)->get("workspace-policy-probe/{$workspace->slug}")->assertNoContent();
});
