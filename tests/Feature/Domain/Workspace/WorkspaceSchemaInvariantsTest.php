<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Actions\CreateWorkspace;
use App\Domain\Workspace\Data\CreateWorkspaceData;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Queries\ResolveWorkspaceForUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('refuses a role the domain does not define', function (string $column, string $value): void {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    expect(fn () => DB::table('workspace_memberships')->insert([
        'id' => (string) Str::uuid7(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $column === 'role' ? $value : WorkspaceRole::Member->value,
        'status' => $column === 'status' ? $value : WorkspaceMembershipStatus::Active->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->with([
    'role' => ['role', 'superuser'],
    'status' => ['status', 'pending'],
]);

it('leaves every workspace with an active owner membership', function (): void {
    $owner = User::factory()->create();
    app(CreateWorkspace::class)->handle($owner, new CreateWorkspaceData(name: 'Acme'));
    Workspace::factory()->withOwnerMembership()->count(2)->create();

    $orphaned = Workspace::query()
        ->whereDoesntHave('memberships', fn ($memberships) => $memberships
            ->whereColumn('user_id', 'workspaces.owner_id')
            ->where('role', WorkspaceRole::Owner->value)
            ->where('status', WorkspaceMembershipStatus::Active->value))
        ->count();

    expect($orphaned)->toBe(0);
});

it('breaks a created_at tie by key, so the fallback is the earlier workspace', function (): void {
    // created_at has second precision, so a tie is broken by the UUIDv7 key.
    $user = User::factory()->create();
    $first = Workspace::factory()->create(['slug' => 'first']);
    $second = Workspace::factory()->create(['slug' => 'second']);
    $tie = now()->startOfSecond();

    foreach ([$first, $second] as $workspace) {
        memberOf($workspace, WorkspaceRole::Member, user: $user);
        DB::table('workspaces')->where('id', $workspace->id)->update(['created_at' => $tie]);
    }

    $expected = collect([$first, $second])->sortBy('id')->first();

    $resolved = app(ResolveWorkspaceForUser::class)($user->refresh());

    expect($resolved?->slug)->toBe($expected?->slug);
});
