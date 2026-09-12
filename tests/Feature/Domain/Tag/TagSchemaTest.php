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

    expect(DB::table('tags')->where('name', 'Bug')->count())->toBe(2);
});

it('refuses a colour that is not in the palette', function (): void {
    $workspace = Workspace::factory()->create();

    // An invalid value would otherwise be stored and then throw in the cast on every read.
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

it('reads its colour back as the accent it is, palette or chosen', function (): void {
    $workspace = Workspace::factory()->create();
    $tag = Tag::factory()->in($workspace)->create(['color' => ProjectColor::Teal]);

    expect($tag->fresh()?->color?->paletteColor())->toBe(ProjectColor::Teal)
        ->and($tag->fresh()?->color?->isCustom())->toBeFalse();

    $tag->update(['color' => '#3F7D5A']);

    expect($tag->fresh()?->color?->value)->toBe('#3f7d5a')
        ->and($tag->fresh()?->color?->isCustom())->toBeTrue();
});

it('refuses to have its workspace mass assigned', function (): void {
    expect(fn (): Tag => (new Tag)->fill(['name' => 'Bug', 'workspace_id' => 'anything']))
        ->toThrow(MassAssignmentException::class);
});

it('stores a hex colour and refuses anything the palette and the pattern both reject', function (): void {
    $workspace = Workspace::factory()->create();

    insertTag($workspace, 'Chosen', ['color' => '#3f7d5a']);

    expect(DB::table('tags')->where('name', 'Chosen')->value('color'))->toBe('#3f7d5a');

    // The application lower-cases hex colours, so the constraint rejects upper case. See ADR-0021.
    expect(fn () => insertTag($workspace, 'Shouty', ['color' => '#3F7D5A']))
        ->toThrow(QueryException::class);

    expect(fn () => insertTag($workspace, 'Nonsense', ['color' => 'burgundy']))
        ->toThrow(QueryException::class);
});
