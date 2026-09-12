<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\LazyLoadingViolationException;

it('exposes only active memberships as members', function (): void {
    $workspace = Workspace::factory()->create();
    $active = User::factory()->create();
    $invited = User::factory()->create();

    WorkspaceMembership::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $active->id,
    ]);
    WorkspaceMembership::factory()->withStatus(WorkspaceMembershipStatus::Invited)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $invited->id,
    ]);

    expect($workspace->members)->toHaveCount(1)
        ->and($workspace->members->first()->is($active))->toBeTrue()
        ->and($workspace->users)->toHaveCount(2)
        ->and($workspace->memberships)->toHaveCount(2);
});

it('lists the workspaces a user actively belongs to', function (): void {
    $user = User::factory()->create();
    $joined = Workspace::factory()->create();
    $pending = Workspace::factory()->create();

    WorkspaceMembership::factory()->active()->create([
        'workspace_id' => $joined->id,
        'user_id' => $user->id,
    ]);
    WorkspaceMembership::factory()->withStatus(WorkspaceMembershipStatus::Revoked)->create([
        'workspace_id' => $pending->id,
        'user_id' => $user->id,
    ]);

    expect($user->workspaces)->toHaveCount(1)
        ->and($user->workspaces->firstOrFail()->is($joined))->toBeTrue()
        ->and($user->workspaceMemberships)->toHaveCount(2);
});

it('carries the role on the pivot so a listing needs no second query', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMembership::factory()->owner()->active()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $pivot = $user->workspaces()->first()?->getRelationValue('pivot');

    expect($pivot?->getAttribute('role'))->toBe('owner');
});

// Eloquent only arms the lazy-loading guard for result sets with more than one row.
it('resolves memberships without an n+1 when eager loaded', function (): void {
    $users = User::factory()->count(2)->create()->all();
    $workspaces = Workspace::factory()->count(2)->create();

    foreach ($workspaces as $index => $workspace) {
        WorkspaceMembership::factory()->active()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $users[$index]->id,
        ]);
    }

    $eager = Workspace::query()->with('memberships.user')->get();

    expect($eager->pluck('memberships')->flatten()->pluck('user')->filter())->toHaveCount(2);

    $lazy = Workspace::query()->get();

    expect(fn (): mixed => $lazy->firstOrFail()->memberships)
        ->toThrow(LazyLoadingViolationException::class);
});
