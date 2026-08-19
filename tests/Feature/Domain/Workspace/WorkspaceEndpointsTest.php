<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
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

it('requires authentication on every workspace route', function (string $method, string $route): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $url = in_array($route, ['workspaces.switch'], true) ? route($route, $workspace->slug) : route($route);

    $this->{$method}($url)->assertRedirect(route('login'));
})->with([
    'index' => ['get', 'workspaces.index'],
    'create' => ['get', 'workspaces.create'],
    'store' => ['post', 'workspaces.store'],
    'switch' => ['post', 'workspaces.switch'],
    'edit' => ['get', 'workspaces.edit'],
    'update' => ['put', 'workspaces.update'],
]);

it('guards every workspace route with auth and verified', function (): void {
    /*
     * The route table as well as the behaviour: the request test below proves `verified`
     * blocks an unverified actor, and this proves the middleware is on every route rather
     * than on the ones that happen to be tested.
     */
    $routes = collect(Route::getRoutes()->getRoutesByName())
        ->filter(fn ($route, string $name): bool => str_starts_with($name, 'workspaces.'));

    expect($routes)->toHaveCount(6);

    $routes->each(function ($route): void {
        expect($route->gatherMiddleware())->toContain('auth')->toContain('verified');
    });
});

it('renders the create form', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('workspaces.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('workspaces/Create'));
});

it('renders an empty listing for someone who belongs to no workspace', function (): void {
    Workspace::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('workspaces.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('workspaces', 0));
});

it('puts the creator into the workspace they just created', function (): void {
    $existing = Workspace::factory()->create(['slug' => 'existing']);
    $user = memberOf($existing, WorkspaceRole::Owner);
    $user->forceFill(['current_workspace_id' => $existing->id])->save();

    $this->actingAs($user)->post(route('workspaces.store'), ['name' => 'Beta']);

    expect($user->fresh()?->current_workspace_id)->not->toBe($existing->id);

    $this->actingAs($user->fresh())
        ->get(route('workspaces.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('workspace.slug', 'beta'));
});

it('persists the slug and timezone the create form sent', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('workspaces.store'), [
            'name' => 'Acme Industries',
            'slug' => 'acme-eu',
            'timezone' => 'Europe/Prague',
        ]);

    $this->assertDatabaseHas('workspaces', [
        'name' => 'Acme Industries',
        'slug' => 'acme-eu',
        'timezone' => 'Europe/Prague',
    ]);
});

it('creates exactly one membership, for the creator, as an active owner', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('workspaces.store'), ['name' => 'Acme']);

    $this->assertDatabaseCount('workspace_memberships', 1);
    $this->assertDatabaseHas('workspace_memberships', [
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner->value,
        'status' => WorkspaceMembershipStatus::Active->value,
    ]);
});

it('rejects a create without a name and stores nothing', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('workspaces.store'), [])
        ->assertInvalid('name');

    $this->assertDatabaseCount('workspaces', 0);
});

it('rejects an update with a blank name and leaves the row alone', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $admin = memberOf($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->put(route('workspaces.update'), ['id' => $workspace->id, 'name' => ''])
        ->assertInvalid('name');

    expect($workspace->fresh()?->name)->toBe('Acme');
});

it('lets a workspace keep its own slug and rejects one another workspace holds', function (): void {
    Workspace::factory()->create(['slug' => 'taken']);
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $admin = memberOf($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->put(route('workspaces.update'), ['id' => $workspace->id, 'name' => 'Acme', 'slug' => 'acme'])
        ->assertValid();

    $this->actingAs($admin)
        ->put(route('workspaces.update'), ['id' => $workspace->id, 'name' => 'Acme', 'slug' => 'taken'])
        ->assertInvalid('slug');

    expect($workspace->fresh()?->slug)->toBe('acme');
});

it('ignores an owner or settings smuggled through the workspace forms', function (): void {
    $intruder = User::factory()->create();
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $ownerId = $workspace->owner_id;

    $this->actingAs($admin)->put(route('workspaces.update'), [
        'id' => $workspace->id,
        'name' => 'Acme',
        'owner_id' => $intruder->id,
        'settings' => ['injected' => true],
    ]);

    expect($workspace->fresh()?->owner_id)->toBe($ownerId)
        ->and($workspace->fresh()?->settings)->toBe([]);

    $this->actingAs($admin)->post(route('workspaces.store'), [
        'name' => 'Beta',
        'owner_id' => $intruder->id,
    ]);

    $beta = Workspace::query()->where('slug', 'beta')->firstOrFail();

    expect($beta->owner_id)->toBe($admin->id);
});

it('lets an owner update the workspace and offers them deletion', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $owner = memberOf($workspace, WorkspaceRole::Owner);

    $this->actingAs($owner)
        ->get(route('workspaces.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('can.update', true)
            ->where('can.delete', true));

    $this->actingAs($owner)
        ->put(route('workspaces.update'), ['id' => $workspace->id, 'name' => 'Acme Industries'])
        ->assertRedirect(route('workspaces.edit'));

    expect($workspace->fresh()?->name)->toBe('Acme Industries');
});

it('refuses the update to a guest', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    $this->actingAs($guest)
        ->put(route('workspaces.update'), ['id' => $workspace->id, 'name' => 'Renamed'])
        ->assertForbidden();

    expect($workspace->fresh()?->name)->toBe('Acme');
});

it('refuses to read or write the settings on a membership that is not active', function (WorkspaceMembershipStatus $status): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $actor = memberOf($workspace, WorkspaceRole::Owner, $status);

    $this->actingAs($actor)->get(route('workspaces.edit'))->assertNotFound();

    $this->actingAs($actor)
        ->put(route('workspaces.update'), ['id' => $workspace->id, 'name' => 'Renamed'])
        ->assertForbidden();

    expect($workspace->fresh()?->name)->toBe('Acme');
})->with([
    'invited' => WorkspaceMembershipStatus::Invited,
    'declined' => WorkspaceMembershipStatus::Declined,
    'revoked' => WorkspaceMembershipStatus::Revoked,
    'expired' => WorkspaceMembershipStatus::Expired,
]);

it('keeps a workspace the actor no longer belongs to out of the listing and the shell', function (): void {
    $kept = Workspace::factory()->create(['slug' => 'kept', 'name' => 'Kept']);
    $left = Workspace::factory()->create(['slug' => 'left', 'name' => 'Left']);
    $user = memberOf($kept, WorkspaceRole::Member);
    memberOf($left, WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked, user: $user);

    $this->actingAs($user)
        ->get(route('workspaces.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('workspaces', 1)
            ->where('workspaces.0.slug', 'kept'));
});

it('shares only the user fields the client renders', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $member = memberOf($workspace, WorkspaceRole::Member);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('auth.user', fn (AssertableInertia $user): AssertableInertia => $user
                ->hasAll(['id', 'name', 'email', 'email_verified_at', 'avatar'])
                ->etc()
                ->missing('password')
                ->missing('two_factor_secret')
                ->missing('two_factor_recovery_codes')
                ->missing('remember_token')
                ->missing('current_workspace_id')));
});

it('sends the capabilities the actor holds in the resolved workspace', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $member = memberOf($workspace, WorkspaceRole::Member);
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.capabilities', fn (Collection $capabilities): bool => $capabilities->contains('project.create')
                && ! $capabilities->contains('workspace.manage')));

    $this->actingAs($guest)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.capabilities', fn (Collection $capabilities): bool => $capabilities->toArray() === ['comment.create']));
});

it('sends no capabilities on a membership that is not active', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $revoked = memberOf($workspace, WorkspaceRole::Owner, WorkspaceMembershipStatus::Revoked);

    $this->actingAs($revoked)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.capabilities', [])
            ->where('workspace', null));
});

it('keeps an unverified account out of every workspace route', function (string $method, string $route): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $unverified = memberOf(
        $workspace,
        WorkspaceRole::Owner,
        user: User::factory()->unverified()->create(),
    );

    $url = $route === 'workspaces.switch' ? route($route, $workspace->slug) : route($route);

    $this->actingAs($unverified)->{$method}($url)->assertRedirect(route('verification.notice'));
})->with([
    'index' => ['get', 'workspaces.index'],
    'create' => ['get', 'workspaces.create'],
    'store' => ['post', 'workspaces.store'],
    'switch' => ['post', 'workspaces.switch'],
    'edit' => ['get', 'workspaces.edit'],
    'update' => ['put', 'workspaces.update'],
]);

it('lets the same account through once its address is verified', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $user = memberOf($workspace, WorkspaceRole::Owner, user: User::factory()->unverified()->create());

    $this->actingAs($user)->get(route('workspaces.index'))->assertRedirect(route('verification.notice'));

    $user->markEmailAsVerified();

    $this->actingAs($user->fresh())->get(route('workspaces.index'))->assertOk();
});
