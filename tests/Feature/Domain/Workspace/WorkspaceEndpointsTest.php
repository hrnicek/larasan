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
        ->assertRedirect(route('workspaces.edit'));

    expect($user->workspaces()->count())->toBe(1);
});

it('shows the settings screen with the abilities the actor has', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $admin = actorIn($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->get(route('workspaces.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('settings/Workspace')
            ->where('workspace.slug', 'acme')
            ->where('can.update', true)
            ->where('can.delete', false));
});

it('hides a workspace the actor does not belong to behind a 404', function (): void {
    Workspace::factory()->create(['slug' => 'acme']);

    $this->actingAs(User::factory()->create())
        ->get(route('workspaces.edit'))
        ->assertNotFound();
});

it('treats a revoked membership as no membership', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $revoked = actorIn($workspace, WorkspaceRole::Owner, WorkspaceMembershipStatus::Revoked);

    $this->actingAs($revoked)->get(route('workspaces.edit'))->assertNotFound();
});

it('updates the workspace for an admin', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $admin = actorIn($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->put(route('workspaces.update'), ['name' => 'Acme Industries', 'timezone' => 'Europe/Prague'])
        ->assertRedirect(route('workspaces.edit'));

    expect($workspace->fresh()?->name)->toBe('Acme Industries')
        ->and($workspace->fresh()?->timezone)->toBe('Europe/Prague');
});

it('refuses the update to a member who cannot manage the workspace', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $member = actorIn($workspace, WorkspaceRole::Member);

    $this->actingAs($member)
        ->put(route('workspaces.update'), ['name' => 'Renamed'])
        ->assertForbidden();

    expect($workspace->fresh()?->name)->toBe('Acme');
});

it('tells the settings screen a member may not change anything', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $member = actorIn($workspace, WorkspaceRole::Member);

    $this->actingAs($member)
        ->get(route('workspaces.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('settings/Workspace')
            ->where('can.update', false)
            ->where('can.delete', false));
});

it('shares the actor\'s workspaces with every page for the switcher', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    Workspace::factory()->create(['slug' => 'not-mine']);
    $member = actorIn($workspace, WorkspaceRole::Member);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('workspaces', 1)
            ->where('workspaces.0.name', 'Acme')
            ->where('workspace.slug', 'acme'));
});
