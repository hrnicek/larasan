<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * No route in this slice lets a caller name another tenant's workspace by id: the
 * settings routes carry no parameter and the switch route takes a slug. Isolation
 * therefore has three handles worth attacking — the ambient pointer, the global slug
 * namespace, and the membership table — and a test that merely posts another tenant's
 * slug to the switch route restates the middleware instead of proving anything.
 */

function forgePointerTo(User $user, Workspace $workspace): void
{
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
}

it('ignores a pointer aimed at a workspace the actor does not belong to', function (): void {
    $mine = Workspace::factory()->create(['slug' => 'mine', 'name' => 'Mine']);
    $theirs = Workspace::factory()->create(['slug' => 'theirs', 'name' => 'Theirs']);
    $user = memberOf($mine, WorkspaceRole::Admin);

    forgePointerTo($user, $theirs);

    $response = $this->actingAs($user)->get(route('workspaces.edit'));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('workspace.slug', 'mine'));

    expect($response->content())->not->toContain('theirs')
        ->and($response->content())->not->toContain('Theirs')
        ->and($response->content())->not->toContain($theirs->id);
});

it('repairs the forged pointer instead of leaving it to the next request', function (): void {
    $mine = Workspace::factory()->create(['slug' => 'mine']);
    $theirs = Workspace::factory()->create(['slug' => 'theirs']);
    $user = memberOf($mine, WorkspaceRole::Member);

    forgePointerTo($user, $theirs);

    $this->actingAs($user)->get(route('dashboard'));

    expect($user->fresh()?->current_workspace_id)->toBe($mine->id);
});

it('gives someone in no workspace nothing to resolve, however their pointer is set', function (): void {
    $theirs = Workspace::factory()->create(['slug' => 'theirs']);
    $outsider = User::factory()->create();

    forgePointerTo($outsider, $theirs);

    $this->actingAs($outsider)->get(route('workspaces.edit'))->assertNotFound();

    $this->actingAs($outsider)
        ->put(route('workspaces.update'), ['id' => $theirs->id, 'name' => 'Renamed'])
        ->assertForbidden();

    expect($theirs->fresh()?->name)->not->toBe('Renamed');
});

it('cannot be made to write another tenant through the pointer', function (): void {
    $mine = Workspace::factory()->create(['slug' => 'mine', 'name' => 'Mine']);
    $theirs = Workspace::factory()->create(['slug' => 'theirs', 'name' => 'Theirs']);
    $admin = memberOf($mine, WorkspaceRole::Admin);

    forgePointerTo($admin, $theirs);

    $this->actingAs($admin)
        ->put(route('workspaces.update'), ['id' => $theirs->id, 'name' => 'Renamed'])
        ->assertInvalid('id');

    expect($theirs->fresh()?->name)->toBe('Theirs')
        ->and($theirs->fresh()?->updated_at?->equalTo($theirs->updated_at))->toBeTrue()
        ->and($mine->fresh()?->name)->toBe('Mine');
});

it('does not fall back to a workspace the actor may write when the resolved one denies', function (): void {
    $writable = Workspace::factory()->create(['slug' => 'writable', 'name' => 'Writable']);
    $readable = Workspace::factory()->create(['slug' => 'readable', 'name' => 'Readable']);
    $actor = memberOf($writable, WorkspaceRole::Admin);
    memberOf($readable, WorkspaceRole::Guest, user: $actor);

    forgePointerTo($actor, $readable);

    $this->actingAs($actor)
        ->put(route('workspaces.update'), ['id' => $readable->id, 'name' => 'Renamed'])
        ->assertForbidden();

    expect($readable->fresh()?->name)->toBe('Readable')
        ->and($writable->fresh()?->name)->toBe('Writable');
});

it('answers the same way for a slug that is missing and one that is not yours', function (): void {
    $mine = Workspace::factory()->create(['slug' => 'mine']);
    $theirs = Workspace::factory()->create(['slug' => 'theirs']);
    $user = memberOf($mine, WorkspaceRole::Member);

    $missing = $this->actingAs($user)->post(route('workspaces.switch', 'no-such-workspace'));
    $foreign = $this->actingAs($user)->post(route('workspaces.switch', $theirs->slug));

    expect($foreign->status())->toBe($missing->status())
        ->and($foreign->status())->toBe(404);
});

it('refuses a uuid where the route expects a slug, including your own', function (string $which): void {
    $mine = Workspace::factory()->create(['slug' => 'mine']);
    $theirs = Workspace::factory()->create(['slug' => 'theirs']);
    $user = memberOf($mine, WorkspaceRole::Member);
    forgePointerTo($user, $mine);

    $target = $which === 'mine' ? $mine : $theirs;

    $this->actingAs($user)->post(route('workspaces.switch', $target->id))->assertNotFound();

    expect($user->fresh()?->current_workspace_id)->toBe($mine->id);
})->with(['mine', 'theirs']);

it('creates no membership when a switch is refused', function (): void {
    $mine = Workspace::factory()->create(['slug' => 'mine']);
    $theirs = Workspace::factory()->create(['slug' => 'theirs']);
    $user = memberOf($mine, WorkspaceRole::Member);

    $this->actingAs($user)->post(route('workspaces.switch', 'theirs'))->assertNotFound();

    $this->assertDatabaseMissing('workspace_memberships', [
        'workspace_id' => $theirs->id,
        'user_id' => $user->id,
    ]);
});

it('keeps other tenants out of the props shared with every page', function (WorkspaceMembershipStatus $status): void {
    $mine = Workspace::factory()->create(['slug' => 'mine', 'name' => 'Mine']);
    $theirs = Workspace::factory()->create(['slug' => 'theirs', 'name' => 'Theirs']);
    $user = memberOf($mine, WorkspaceRole::Member);
    memberOf($theirs, WorkspaceRole::Owner, $status, user: $user);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('workspaces', 1)
            ->where('workspaces.0.slug', 'mine')
            ->where('workspace.slug', 'mine'));
})->with([
    'invited' => WorkspaceMembershipStatus::Invited,
    'revoked' => WorkspaceMembershipStatus::Revoked,
    'expired' => WorkspaceMembershipStatus::Expired,
]);

it('refuses a membership for a workspace that does not exist', function (): void {
    $user = User::factory()->create();

    expect(fn () => DB::table('workspace_memberships')->insert([
        'id' => (string) Str::uuid7(),
        'workspace_id' => (string) Str::uuid7(),
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner->value,
        'status' => WorkspaceMembershipStatus::Active->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses a membership for a user that does not exist', function (): void {
    $workspace = Workspace::factory()->create();

    expect(fn () => DB::table('workspace_memberships')->insert([
        'id' => (string) Str::uuid7(),
        'workspace_id' => $workspace->id,
        'user_id' => 999999,
        'role' => WorkspaceRole::Owner->value,
        'status' => WorkspaceMembershipStatus::Active->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses a pointer at a workspace that does not exist', function (): void {
    $user = User::factory()->create();

    expect(fn (): bool => $user->forceFill(['current_workspace_id' => (string) Str::uuid7()])->save())
        ->toThrow(QueryException::class);
});
