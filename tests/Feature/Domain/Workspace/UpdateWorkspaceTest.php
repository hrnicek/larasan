<?php

declare(strict_types=1);

use App\Domain\Workspace\Actions\UpdateWorkspace;
use App\Domain\Workspace\Data\UpdateWorkspaceData;
use App\Domain\Workspace\Events\WorkspaceUpdated;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function updateWorkspace(Workspace $workspace, UpdateWorkspaceData $data): Workspace
{
    return app(UpdateWorkspace::class)->handle($workspace, $data);
}

it('changes the name without touching the slug', function (): void {
    $workspace = Workspace::factory()->create(['name' => 'Acme', 'slug' => 'acme']);

    $updated = updateWorkspace($workspace, new UpdateWorkspaceData(name: 'Acme Industries'));

    expect($updated->name)->toBe('Acme Industries')
        ->and($updated->slug)->toBe('acme')
        ->and($updated->fresh()?->name)->toBe('Acme Industries');
});

it('leaves the owner alone', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->ownedBy($owner)->create();

    updateWorkspace($workspace, new UpdateWorkspaceData(name: 'Renamed'));

    expect($workspace->fresh()?->owner_id)->toBe($owner->id);
});

it('rejects a slug another workspace already holds', function (): void {
    Workspace::factory()->create(['slug' => 'taken']);
    $workspace = Workspace::factory()->create(['slug' => 'mine']);

    // Savepoint: PostgreSQL aborts the whole transaction on a failed statement.
    expect(fn (): Workspace => DB::transaction(fn (): Workspace => updateWorkspace(
        $workspace,
        new UpdateWorkspaceData(name: 'Mine', slug: 'taken'),
    )))->toThrow(QueryException::class);

    expect($workspace->fresh()?->slug)->toBe('mine');
});

it('dispatches the update with the attributes that changed', function (): void {
    Event::fake();
    $workspace = Workspace::factory()->create(['name' => 'Acme', 'timezone' => 'UTC']);

    updateWorkspace($workspace, new UpdateWorkspaceData(name: 'Acme', timezone: 'Europe/Prague'));

    Event::assertDispatched(WorkspaceUpdated::class, fn (WorkspaceUpdated $event): bool => $event->workspaceId === $workspace->id && $event->changed === ['timezone']);
});

it('dispatches nothing when the update changes nothing', function (): void {
    Event::fake();
    $workspace = Workspace::factory()->create(['name' => 'Acme', 'timezone' => 'UTC']);

    updateWorkspace($workspace, new UpdateWorkspaceData(name: 'Acme', timezone: 'UTC'));

    Event::assertNotDispatched(WorkspaceUpdated::class);
});
