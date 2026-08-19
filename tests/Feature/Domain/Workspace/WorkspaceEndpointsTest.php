<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('requires authentication', function (): void {
    $this->get(route('workspaces.index'))->assertRedirect(route('login'));
});

it('lists only the workspaces the actor belongs to', function (): void {
    $mine = Workspace::factory()->create(['name' => 'Mine']);
    Workspace::factory()->create(['name' => 'Someone else']);
    $user = memberOf($mine, WorkspaceRole::Member);

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
    $admin = memberOf($workspace, WorkspaceRole::Admin);

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
    $revoked = memberOf($workspace, WorkspaceRole::Owner, WorkspaceMembershipStatus::Revoked);

    $this->actingAs($revoked)->get(route('workspaces.edit'))->assertNotFound();
});

it('updates the workspace for an admin', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $admin = memberOf($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->put(route('workspaces.update'), ['id' => $workspace->id, 'name' => 'Acme Industries', 'timezone' => 'Europe/Prague'])
        ->assertRedirect(route('workspaces.edit'));

    expect($workspace->fresh()?->name)->toBe('Acme Industries')
        ->and($workspace->fresh()?->timezone)->toBe('Europe/Prague');
});

it('refuses the update to a member who cannot manage the workspace', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $member = memberOf($workspace, WorkspaceRole::Member);

    $this->actingAs($member)
        ->put(route('workspaces.update'), ['id' => $workspace->id, 'name' => 'Renamed'])
        ->assertForbidden();

    expect($workspace->fresh()?->name)->toBe('Acme');
});

it('tells the settings screen a member may not change anything', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $member = memberOf($workspace, WorkspaceRole::Member);

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
    $member = memberOf($workspace, WorkspaceRole::Member);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('workspaces', 1)
            ->where('workspaces.0.name', 'Acme')
            ->where('workspace.slug', 'acme'));
});

it('refuses an update whose form was rendered for another workspace', function (): void {
    /*
     * The route names no workspace, so the target is the pointer: open settings for A,
     * switch to B in another tab, submit. Both are legitimate admins' workspaces, so
     * every check upstream passes and A's values would land in B.
     */
    $current = Workspace::factory()->create(['slug' => 'current', 'name' => 'Current']);
    $stale = Workspace::factory()->create(['slug' => 'stale', 'name' => 'Stale']);
    $admin = memberOf($current, WorkspaceRole::Admin);
    memberOf($stale, WorkspaceRole::Admin, user: $admin);

    $this->actingAs($admin)
        ->put(route('workspaces.update'), ['id' => $stale->id, 'name' => 'Renamed'])
        ->assertInvalid('id');

    expect($current->fresh()?->name)->toBe('Current')
        ->and($stale->fresh()?->name)->toBe('Stale');
});
