<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Schema-level guarantees, asserted before the model exists so the constraints are shown
 * to be the database's rather than the model's.
 */
function insertMembership(Workspace $workspace, User $user, ?string $id = null): void
{
    DB::table('workspace_memberships')->insert([
        'id' => $id ?? (string) Str::uuid7(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member->value,
        'status' => WorkspaceMembershipStatus::Active->value,
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('rejects a second membership for the same user in the same workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    insertMembership($workspace, $user);

    expect(fn () => insertMembership($workspace, $user))->toThrow(QueryException::class);
});

it('allows the same user in two workspaces', function (): void {
    $user = User::factory()->create();

    insertMembership(Workspace::factory()->create(), $user);
    insertMembership(Workspace::factory()->create(), $user);

    expect(DB::table('workspace_memberships')->where('user_id', $user->id)->count())->toBe(2);
});

it('deletes memberships with their workspace', function (): void {
    $workspace = Workspace::factory()->create();
    insertMembership($workspace, User::factory()->create());

    $workspace->delete();

    expect(DB::table('workspace_memberships')->count())->toBe(0);
});

it('requires a role and a status to be given explicitly', function (): void {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    expect(fn () => DB::table('workspace_memberships')->insert([
        'id' => (string) Str::uuid7(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
