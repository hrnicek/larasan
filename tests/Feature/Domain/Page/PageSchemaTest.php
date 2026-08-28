<?php

declare(strict_types=1);

use App\Domain\Page\Models\Page;
use App\Domain\Project\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asserted through raw inserts, before the model is involved, so what is proven is the
 * database's behaviour rather than an Action's.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertPage(Project $project, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('pages')->insert([
        'id' => $id,
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'parent_id' => null,
        'title' => 'Brief',
        'content' => json_encode(['type' => 'doc', 'content' => []]),
        'excerpt' => null,
        'position' => 65536,
        'version' => 1,
        'created_by' => null,
        'updated_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

it('refuses two root pages in the same slot of one project', function (): void {
    $project = Project::factory()->create();
    insertPage($project);

    // Savepoint: PostgreSQL aborts the whole transaction on a failed statement, and
    // RefreshDatabase already holds one (docs/conventions/testing.md). The NULL parent is the point —
    // without NULLS NOT DISTINCT the constraint would not see these two rows as siblings.
    expect(fn (): string => DB::transaction(fn (): string => insertPage($project, ['title' => 'Notes'])))
        ->toThrow(QueryException::class);

    expect(DB::table('pages')->count())->toBe(1);
});

it('refuses two children of one page in the same slot', function (): void {
    $project = Project::factory()->create();
    $parent = insertPage($project);

    insertPage($project, ['parent_id' => $parent, 'position' => 131072]);

    expect(fn (): string => DB::transaction(fn (): string => insertPage($project, [
        'parent_id' => $parent,
        'position' => 131072,
    ])))->toThrow(QueryException::class);
});

it('lets a child sit in the same slot as a root page', function (): void {
    $project = Project::factory()->create();
    $parent = insertPage($project);

    insertPage($project, ['parent_id' => $parent]);

    expect(DB::table('pages')->count())->toBe(2);
});

it('takes the subtree with the page it hangs from', function (): void {
    $project = Project::factory()->create();
    $parent = Page::factory()->in($project)->create();
    Page::factory()->under($parent)->create();

    DB::table('pages')->where('id', $parent->id)->delete();

    expect(DB::table('pages')->count())->toBe(0);
});

it('takes the pages with the project', function (): void {
    $project = Project::factory()->create();
    Page::factory()->in($project)->create();

    DB::table('projects')->where('id', $project->id)->delete();

    expect(DB::table('pages')->count())->toBe(0);
});

it('refuses a parent that does not exist', function (): void {
    $project = Project::factory()->create();

    expect(fn (): string => DB::transaction(fn (): string => insertPage($project, [
        'parent_id' => (string) Str::uuid7(),
    ])))->toThrow(QueryException::class);
});
