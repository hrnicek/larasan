<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Actions\CreateWorkspace;
use App\Domain\Workspace\Data\CreateWorkspaceData;
use App\Domain\Workspace\Events\WorkspaceCreated;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

function createWorkspace(User $owner, CreateWorkspaceData $data): Workspace
{
    return app(CreateWorkspace::class)->handle($owner, $data);
}

it('creates the workspace and the owner membership together', function (): void {
    $owner = User::factory()->create();

    $workspace = createWorkspace($owner, new CreateWorkspaceData(name: 'Acme Industries'));

    $membership = WorkspaceMembership::query()
        ->where('workspace_id', $workspace->id)
        ->where('user_id', $owner->id)
        ->firstOrFail();

    expect($workspace->slug)->toBe('acme-industries')
        ->and($workspace->owner_id)->toBe($owner->id)
        ->and($membership->role)->toBe(WorkspaceRole::Owner)
        ->and($membership->status)->toBe(WorkspaceMembershipStatus::Active)
        ->and($membership->joined_at)->not->toBeNull();
});

it('makes the workspace reachable by its creator', function (): void {
    $owner = User::factory()->create();

    $workspace = createWorkspace($owner, new CreateWorkspaceData(name: 'Acme'));

    expect($owner->workspaces()->pluck('workspaces.id')->all())->toBe([$workspace->id]);
});

it('resolves a slug collision rather than failing', function (): void {
    $owner = User::factory()->create();

    $first = createWorkspace($owner, new CreateWorkspaceData(name: 'Acme'));
    $second = createWorkspace($owner, new CreateWorkspaceData(name: 'Acme'));

    expect($second->slug)->not->toBe($first->slug)->toStartWith('acme-');
});

it('rolls the membership back when the workspace insert fails', function (): void {
    $owner = User::factory()->create();
    Workspace::factory()->create(['slug' => 'taken']);

    expect(fn (): Workspace => createWorkspace($owner, new CreateWorkspaceData(name: 'Taken', slug: 'taken')))
        ->toThrow(QueryException::class);

    expect(Workspace::query()->where('owner_id', $owner->id)->count())->toBe(0)
        ->and(WorkspaceMembership::query()->where('user_id', $owner->id)->count())->toBe(0);
});

it('dispatches WorkspaceCreated with ids a queued listener can re-read', function (): void {
    Event::fake();
    $owner = User::factory()->create();

    $workspace = createWorkspace($owner, new CreateWorkspaceData(name: 'Acme'));

    Event::assertDispatched(WorkspaceCreated::class, fn (WorkspaceCreated $event): bool => $event->workspaceId === $workspace->id
        && $event->ownerId === $owner->id);
});

it('honours a caller-supplied slug and timezone', function (): void {
    $owner = User::factory()->create();

    $workspace = createWorkspace($owner, new CreateWorkspaceData(
        name: 'Acme',
        slug: 'acme-eu',
        timezone: 'Europe/Prague',
    ));

    expect($workspace->slug)->toBe('acme-eu')
        ->and($workspace->timezone)->toBe('Europe/Prague');
});
