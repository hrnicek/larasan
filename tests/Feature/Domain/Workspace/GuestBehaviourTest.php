<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;

it('holds exactly one capability', function (): void {
    expect(WorkspaceRole::Guest->capabilities())->toBe([Capability::CommentCreate]);
});

it('can see the workspace it was invited into', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    $this->actingAs($guest)
        ->get(route('workspaces.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('workspace.slug', 'acme')
            ->where('can.update', false)
            ->where('can.delete', false));
});

it('is told it holds one capability and no more', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    $this->actingAs($guest)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.capabilities', ['comment.create']));
});

it('is refused every workspace-level capability', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $gate = Gate::forUser($guest);

    $refused = collect(Capability::cases())
        ->reject(fn (Capability $capability): bool => $capability === Capability::CommentCreate);

    foreach ($refused as $capability) {
        expect($gate->allows($capability->value, $workspace))->toBeFalse();
    }

    expect($refused)->toHaveCount(18);
});

it('cannot change the workspace it can see', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    $this->actingAs($guest)
        ->put(route('workspaces.update'), ['id' => $workspace->id, 'name' => 'Renamed'])
        ->assertForbidden();

    expect($workspace->fresh()?->name)->toBe('Acme');
});

it('may still create a workspace of their own, where they are the owner', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    $this->actingAs($guest)->post(route('workspaces.store'), ['name' => 'Mine'])->assertRedirect();

    $own = Workspace::query()->where('slug', 'mine')->firstOrFail();

    expect($own->membershipFor($guest)?->role)->toBe(WorkspaceRole::Owner)
        ->and($own->membershipFor($guest)?->allows(Capability::WorkspaceManage))->toBeTrue()
        ->and($workspace->membershipFor($guest)?->role)->toBe(WorkspaceRole::Guest);
});
