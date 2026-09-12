<?php

declare(strict_types=1);

use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;

it('generates a uuidv7 key and casts settings', function (): void {
    $workspace = Workspace::factory()->create(['settings' => ['week_starts_on' => 'monday']]);

    expect($workspace->id)->toBeUuid()
        ->and($workspace->settings)->toBe(['week_starts_on' => 'monday'])
        ->and($workspace->getKeyType())->toBe('string')
        ->and($workspace->getIncrementing())->toBeFalse();
});

it('belongs to its owner', function (): void {
    $owner = User::factory()->create();

    $workspace = Workspace::factory()->ownedBy($owner)->create();

    expect($workspace->owner->is($owner))->toBeTrue();
});

it('derives a deterministic slug from the name', function (): void {
    expect(Workspace::slugFor('Acme Industries'))->toBe('acme-industries');
});

it('suffixes the slug when the name is already taken', function (): void {
    Workspace::factory()->create(['name' => 'Acme', 'slug' => 'acme']);

    $slug = Workspace::slugFor('Acme');

    expect($slug)->toBe('acme-2');
});

it('falls back when a name slugifies to nothing', function (): void {
    expect(Workspace::slugFor('日本語'))->toBe('workspace');
});

it('rejects a duplicate slug in the database, not only in validation', function (): void {
    Workspace::factory()->create(['slug' => 'acme']);

    expect(fn (): Workspace => Workspace::factory()->create(['slug' => 'acme']))
        ->toThrow(QueryException::class);
});

it('refuses to delete a user who owns a workspace', function (): void {
    $owner = User::factory()->create();
    Workspace::factory()->ownedBy($owner)->create();

    expect(fn (): ?bool => $owner->delete())->toThrow(QueryException::class);
});

it('counts past every slug that is already taken', function (): void {
    Workspace::factory()->create(['slug' => 'acme']);
    Workspace::factory()->create(['slug' => 'acme-2']);

    expect(Workspace::slugFor('Acme'))->toBe('acme-3');
});
