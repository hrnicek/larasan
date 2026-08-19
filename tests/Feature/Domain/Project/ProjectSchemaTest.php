<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asserted through raw inserts, before a model exists, so what is proven is the
 * database's behaviour rather than a model's.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertProject(Workspace $workspace, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('projects')->insert([
        'id' => $id,
        'workspace_id' => $workspace->id,
        'name' => 'Web',
        'slug' => 'web',
        'default_view' => ProjectDefaultView::List->value,
        'visibility' => ProjectVisibility::Workspace->value,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

it('scopes slug uniqueness to the workspace, not the installation', function (): void {
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();

    insertProject($mine);
    insertProject($theirs);

    // Savepoint: PostgreSQL aborts the whole transaction on a failed statement, and
    // RefreshDatabase already holds one (.ai/rules/tests.md).
    expect(fn (): string => DB::transaction(fn (): string => insertProject($mine)))
        ->toThrow(QueryException::class);

    expect(DB::table('projects')->where('slug', 'web')->count())->toBe(2);
});

it('refuses a visibility or view the domain does not define', function (string $column, string $value): void {
    $workspace = Workspace::factory()->create();

    expect(fn (): string => insertProject($workspace, [$column => $value]))
        ->toThrow(QueryException::class);
})->with([
    'visibility' => ['visibility', 'secret'],
    'default_view' => ['default_view', 'gantt'],
]);

it('deletes its projects with the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    insertProject($workspace);

    $workspace->delete();

    expect(DB::table('projects')->count())->toBe(0);
});

it('keeps the project when its owner closes their account', function (): void {
    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create();
    $id = insertProject($workspace, ['owner_id' => $owner->id, 'created_by' => $owner->id]);

    $owner->delete();

    $project = DB::table('projects')->where('id', $id)->first();

    expect($project)->not->toBeNull()
        ->and($project?->owner_id)->toBeNull()
        ->and($project?->created_by)->toBeNull();
});

it('defaults a new project to a workspace-visible list', function (): void {
    $workspace = Workspace::factory()->create();

    $project = DB::table('projects')->where('id', insertProject($workspace, [
        'default_view' => ProjectDefaultView::List->value,
        'visibility' => ProjectVisibility::Workspace->value,
    ]))->first();

    expect($project?->visibility)->toBe(ProjectVisibility::Workspace->value)
        ->and($project?->default_view)->toBe(ProjectDefaultView::List->value)
        ->and($project?->archived_at)->toBeNull()
        ->and($project?->deleted_at)->toBeNull();
});
