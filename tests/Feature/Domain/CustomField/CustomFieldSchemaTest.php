<?php

declare(strict_types=1);

use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('belongs to a workspace and goes with it', function (): void {
    $workspace = Workspace::factory()->create();
    insertOption(insertCustomField($workspace, 'Stage'), 'Draft', 1);

    $workspace->delete();

    expect(DB::table('custom_fields')->count())->toBe(0)
        ->and(DB::table('custom_field_options')->count())->toBe(0);
});

it('refuses two fields whose names differ only in case', function (): void {
    $workspace = Workspace::factory()->create();
    insertCustomField($workspace, 'Estimate');

    expect(fn (): string => DB::transaction(fn (): string => insertCustomField($workspace, 'estimate')))
        ->toThrow(QueryException::class);
});

it('lets two workspaces record the same thing', function (): void {
    insertCustomField(Workspace::factory()->create(), 'Estimate');
    insertCustomField(Workspace::factory()->create(), 'Estimate');

    expect(DB::table('custom_fields')->count())->toBe(2);
});

it('refuses a type nothing could store', function (): void {
    $workspace = Workspace::factory()->create();

    expect(fn (): string => DB::transaction(fn (): string => insertCustomField($workspace, 'Mystery', ['type' => 'hologram'])))
        ->toThrow(QueryException::class);
});

it('refuses two options in one slot', function (): void {
    $field = insertCustomField(Workspace::factory()->create(), 'Stage');
    insertOption($field, 'Draft', 1);

    expect(fn (): string => DB::transaction(fn (): string => insertOption($field, 'Review', 1)))
        ->toThrow(QueryException::class);
});

it('lets two fields use the same slot numbers', function (): void {
    $workspace = Workspace::factory()->create();
    insertOption(insertCustomField($workspace, 'Stage'), 'Draft', 1);
    insertOption(insertCustomField($workspace, 'Risk'), 'Low', 1);

    expect(DB::table('custom_field_options')->count())->toBe(2);
});

it('refuses an option colour outside the palette', function (): void {
    $field = insertCustomField(Workspace::factory()->create(), 'Stage');

    expect(fn (): string => DB::transaction(fn (): string => insertOption($field, 'Draft', 1, ['color' => 'chartreuse'])))
        ->toThrow(QueryException::class);
});

it('refuses a field or an option missing what it needs', function (): void {
    $workspace = Workspace::factory()->create();
    $field = insertCustomField($workspace, 'Stage');

    foreach (['workspace_id', 'name', 'type'] as $column) {
        expect(fn (): string => DB::transaction(fn (): string => insertCustomField($workspace, 'Other', [$column => null])))
            ->toThrow(QueryException::class);
    }

    foreach (['custom_field_id', 'label', 'position'] as $column) {
        expect(fn (): string => DB::transaction(fn (): string => insertOption($field, 'Draft', 2, [$column => null])))
            ->toThrow(QueryException::class);
    }
});
