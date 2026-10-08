<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('web')->get('workspace-probe/{workspace?}', function () {
        $workspace = ResolveCurrentWorkspace::from(request());

        return response()->json(['slug' => $workspace?->slug]);
    });
});

it('resolves the workspace named in the route for a member', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);

    $this->actingAs(memberOf($workspace))
        ->get('workspace-probe/acme')
        ->assertOk()
        ->assertJson(['slug' => 'acme']);
});

it('returns 404 rather than 403 for a workspace the actor is not in', function (): void {
    Workspace::factory()->create(['slug' => 'acme']);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get('workspace-probe/acme')->assertNotFound();
});

it('treats a non-active membership as no membership at all', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $revoked = memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Revoked);

    $this->actingAs($revoked)->get('workspace-probe/acme')->assertNotFound();
});

it('falls back to the last used workspace when the route names none', function (): void {
    $first = Workspace::factory()->create(['slug' => 'first']);
    $second = Workspace::factory()->create(['slug' => 'second']);
    $user = User::factory()->create();

    foreach ([$first, $second] as $workspace) {
        WorkspaceMembership::factory()->active()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);
    }

    $user->forceFill(['current_workspace_id' => $second->id])->save();

    $this->actingAs($user)->get('workspace-probe')->assertJson(['slug' => 'second']);
});

it('remembers the workspace the route named', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $user = memberOf($workspace);

    $this->actingAs($user)->get('workspace-probe/acme')->assertOk();

    expect($user->fresh()?->current_workspace_id)->toBe($workspace->id);
});

it('ignores a remembered workspace the actor has since left', function (): void {
    $left = Workspace::factory()->create(['slug' => 'left']);
    $kept = Workspace::factory()->create(['slug' => 'kept']);
    $user = User::factory()->create();

    WorkspaceMembership::factory()->active()->create([
        'workspace_id' => $kept->id,
        'user_id' => $user->id,
    ]);
    WorkspaceMembership::factory()->withStatus(WorkspaceMembershipStatus::Revoked)->create([
        'workspace_id' => $left->id,
        'user_id' => $user->id,
    ]);
    $user->forceFill(['current_workspace_id' => $left->id])->save();

    $this->actingAs($user)->get('workspace-probe')->assertJson(['slug' => 'kept']);
});

it('resolves nothing for a user who belongs to no workspace', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('workspace-probe')
        ->assertJson(['slug' => null]);
});

it('leaves unauthenticated requests alone', function (): void {
    Workspace::factory()->create(['slug' => 'acme']);

    $this->get('workspace-probe/acme')->assertOk()->assertJson(['slug' => null]);
});
