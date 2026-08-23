<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Tag\Models\Tag;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 */
function insertTag(Workspace $workspace, string $name, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('tags')->insert([
        'id' => $id,
        'workspace_id' => $workspace->id,
        'name' => $name,
        'color' => ProjectColor::Amber->value,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

it('belongs to a workspace and goes with it', function (): void {
    $workspace = Workspace::factory()->create();
    insertTag($workspace, 'Bug');

    $workspace->delete();

    expect(DB::table('tags')->count())->toBe(0);
});

it('refuses the same tag twice, whatever case somebody types', function (): void {
    $workspace = Workspace::factory()->create();
    insertTag($workspace, 'Bug');

    /*
     * "Bug" and "bug" are one tag to everybody except a database, and a workspace holding both
     * has a filter that quietly finds half the work.
     */
    expect(fn (): string => DB::transaction(fn (): string => insertTag($workspace, 'bug')))
        ->toThrow(QueryException::class);

    expect(fn (): string => DB::transaction(fn (): string => insertTag($workspace, 'BUG')))
        ->toThrow(QueryException::class);
});

it('lets two workspaces use the same word', function (): void {
    $first = Workspace::factory()->create();
    $second = Workspace::factory()->create();

    insertTag($first, 'Bug');
    insertTag($second, 'Bug');

    // A tag is a workspace's own vocabulary, not the installation's.
    expect(DB::table('tags')->where('name', 'Bug')->count())->toBe(2);
});

it('refuses a colour that is not in the palette', function (): void {
    $workspace = Workspace::factory()->create();

    /*
     * The column is cast to an enum, and a value outside it is accepted silently and then throws
     * inside the cast on every request that reads the row — the same reason `projects.color`
     * carries this constraint.
     */
    expect(fn (): string => DB::transaction(fn (): string => insertTag($workspace, 'Chartreuse', ['color' => 'chartreuse'])))
        ->toThrow(QueryException::class);
});

it('allows a tag with no colour at all', function (): void {
    $workspace = Workspace::factory()->create();
    $id = insertTag($workspace, 'Plain', ['color' => null]);

    expect(DB::table('tags')->where('id', $id)->value('color'))->toBeNull();
});

it('refuses a tag with no workspace or no name', function (): void {
    $workspace = Workspace::factory()->create();

    foreach (['workspace_id' => null, 'name' => null] as $column => $value) {
        expect(fn (): string => DB::transaction(fn (): string => insertTag($workspace, 'Whatever', [$column => $value])))
            ->toThrow(QueryException::class);
    }
});

it('reads its colour back as the palette entry it is', function (): void {
    $workspace = Workspace::factory()->create();
    $tag = Tag::factory()->in($workspace)->create(['color' => ProjectColor::Teal]);

    expect($tag->fresh()?->color)->toBe(ProjectColor::Teal);
});

it('refuses to have its workspace mass assigned', function (): void {
    // Which workspace a tag belongs to is decided by the Action from where the request was made,
    // never by a payload.
    expect(fn (): Tag => (new Tag)->fill(['name' => 'Bug', 'workspace_id' => 'anything']))
        ->toThrow(MassAssignmentException::class);
});
