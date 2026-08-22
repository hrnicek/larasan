<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectColor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asserted through raw inserts, before a model exists, so what is proven is the database's
 * behaviour rather than a model's.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertSection(Project $project, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('sections')->insert([
        'id' => $id,
        'project_id' => $project->id,
        'name' => 'Backlog',
        'color' => null,
        'position' => 65536,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

it('refuses two sections in the same slot of one project', function (): void {
    $project = Project::factory()->create();
    insertSection($project);

    // Savepoint: PostgreSQL aborts the whole transaction on a failed statement, and
    // RefreshDatabase already holds one (.ai/rules/tests.md).
    expect(fn (): string => DB::transaction(fn (): string => insertSection($project, ['name' => 'In progress'])))
        ->toThrow(QueryException::class);

    expect(DB::table('sections')->count())->toBe(1);
});

it('lets two projects use the same position', function (): void {
    insertSection(Project::factory()->create());
    insertSection(Project::factory()->create());

    expect(DB::table('sections')->where('position', 65536)->count())->toBe(2);
});

it('refuses a colour the palette does not define', function (): void {
    expect(fn (): string => insertSection(Project::factory()->create(), ['color' => 'fuchsia']))
        ->toThrow(QueryException::class);
});

it('accepts a palette colour and no colour at all', function (): void {
    $project = Project::factory()->create();

    insertSection($project, ['color' => ProjectColor::Sky->value]);
    insertSection($project, ['position' => 131072, 'color' => null]);

    expect(DB::table('sections')->count())->toBe(2);
});

it('deletes its sections with the project', function (): void {
    $project = Project::factory()->create();
    insertSection($project);

    $project->forceDelete();

    expect(DB::table('sections')->count())->toBe(0);
});

it('refuses a section with no project', function (): void {
    expect(fn (): bool => DB::table('sections')->insert([
        'id' => (string) Str::uuid7(),
        'project_id' => (string) Str::uuid7(),
        'name' => 'Orphan',
        'color' => null,
        'position' => 65536,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
