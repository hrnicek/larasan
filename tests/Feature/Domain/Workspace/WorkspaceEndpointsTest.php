<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

function actorIn(Workspace $workspace, WorkspaceRole $role, WorkspaceMembershipStatus $status = WorkspaceMembershipStatus::Active): User
{
    $user = User::factory()->create();

    WorkspaceMembership::factory()->withStatus($status)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

it('requires authentication', function (): void {
    $this->get(route('workspaces.index'))->assertRedirect(route('login'));
});

it('lists only the workspaces the actor belongs to', function (): void {
    $mine = Workspace::factory()->create(['name' => 'Mine']);
    Workspace::factory()->create(['name' => 'Someone else']);
    $user = actorIn($mine, WorkspaceRole::Member);

    $this->actingAs($user)
        ->get(route('workspaces.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('workspaces/Index')
            ->has('workspaces', 1)
            ->where('workspaces.0.slug', $mine->slug));
});

it('creates a workspace and lands on its settings', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('workspaces.store'), ['name' => 'Acme Industries'])
        ->assertRedirect(route('workspaces.edit', 'acme-industries'));

    expect($user->workspaces()->count())->toBe(1);
});

it('shows the settings screen with the abilities the actor has', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $admin = actorIn($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->get(route('workspaces.edit', 'acme'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('workspaces/Settings')
            ->where('workspace.slug', 'acme')
            ->where('can.update', true)
            ->where('can.delete', false));
});

it('hides a workspace the actor does not belong to behind a 404', function (): void {
    Workspace::factory()->create(['slug' => 'acme']);

    $this->actingAs(User::factory()->create())
        ->get(route('workspaces.edit', 'acme'))
        ->assertNotFound();
});

it('treats a revoked membership as no membership', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $revoked = actorIn($workspace, WorkspaceRole::Owner, WorkspaceMembershipStatus::Revoked);

    $this->actingAs($revoked)->get(route('workspaces.edit', 'acme'))->assertNotFound();
});

it('updates the workspace for an admin', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $admin = actorIn($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->put(route('workspaces.update', 'acme'), ['name' => 'Acme Industries', 'timezone' => 'Europe/Prague'])
        ->assertRedirect(route('workspaces.edit', 'acme'));

    expect($workspace->fresh()?->name)->toBe('Acme Industries')
        ->and($workspace->fresh()?->timezone)->toBe('Europe/Prague');
});

it('refuses the update to a member who cannot manage the workspace', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $member = actorIn($workspace, WorkspaceRole::Member);

    $this->actingAs($member)
        ->put(route('workspaces.update', 'acme'), ['name' => 'Renamed'])
        ->assertForbidden();

    expect($workspace->fresh()?->name)->toBe('Acme');
});
