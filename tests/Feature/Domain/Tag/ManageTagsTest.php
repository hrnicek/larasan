<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\ValueObjects\AccentColor;
use App\Domain\Tag\Actions\CreateTag;
use App\Domain\Tag\Actions\DeleteTag;
use App\Domain\Tag\Actions\UpdateTag;
use App\Domain\Tag\Exceptions\TagException;
use App\Domain\Tag\Models\Tag;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\QueryException;

it('adds a word to the workspace vocabulary', function (): void {
    [$workspace, , $actor] = placeableProject();

    $tag = app(CreateTag::class)->handle($workspace, $actor, '  Bug  ', AccentColor::palette(ProjectColor::Rose));

    expect($tag->name)->toBe('Bug')
        ->and($tag->workspace_id)->toBe($workspace->id);
});

it('refuses a nameless tag', function (): void {
    [$workspace, , $actor] = placeableProject();

    expect(fn (): Tag => app(CreateTag::class)->handle($workspace, $actor, "   \n "))
        ->toThrow(TagException::class, 'A tag needs a name.');
});

it('refuses a duplicate whatever case it is typed in', function (): void {
    [$workspace, , $actor] = placeableProject();
    app(CreateTag::class)->handle($workspace, $actor, 'Bug');

    expect(fn (): Tag => app(CreateTag::class)->handle($workspace, $actor, 'BUG'))
        ->toThrow(TagException::class, 'A tag with that name already exists.');
});

it('refuses somebody without tag.manage, at every operation', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $tag = Tag::factory()->in($workspace)->create();

    expect(fn (): Tag => app(CreateTag::class)->handle($workspace, $guest, 'Bug'))
        ->toThrow(TagException::class, 'You do not have permission to manage tags in this workspace.');

    expect(fn (): Tag => app(UpdateTag::class)->handle($tag, $guest, 'Renamed'))->toThrow(TagException::class);
    expect(fn () => app(DeleteTag::class)->handle($tag, $guest))->toThrow(TagException::class);

    expect($tag->fresh()?->name)->toBe($tag->name);
});

it('lets a member manage tags, because a workspace names its own work', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);

    expect(app(CreateTag::class)->handle($workspace, $member, 'Bug')->exists)->toBeTrue();
});

it('changes a name without touching a colour, and the other way round', function (): void {
    [$workspace, , $actor] = placeableProject();
    $tag = Tag::factory()->in($workspace)->named('Buug')->create(['color' => ProjectColor::Amber]);

    app(UpdateTag::class)->handle($tag, $actor, name: 'Bug');
    expect($tag->fresh()?->color?->paletteColor())->toBe(ProjectColor::Amber);

    app(UpdateTag::class)->handle($tag, $actor, color: AccentColor::palette(ProjectColor::Teal));
    expect($tag->fresh()?->name)->toBe('Bug');

    // A separate flag, because a null color already means "leave unchanged".
    app(UpdateTag::class)->handle($tag, $actor, clearColor: true);
    expect($tag->fresh()?->color)->toBeNull();
});

it('refuses a rename onto a name that is taken', function (): void {
    [$workspace, , $actor] = placeableProject();
    Tag::factory()->in($workspace)->named('Bug')->create();
    $other = Tag::factory()->in($workspace)->named('Docs')->create();

    expect(fn (): Tag => app(UpdateTag::class)->handle($other, $actor, 'bug'))
        ->toThrow(TagException::class, 'A tag with that name already exists.');

    expect($other->fresh()?->name)->toBe('Docs');
});

it('deletes the label rather than the work', function (): void {
    [$workspace, , $actor] = placeableProject();
    $tag = Tag::factory()->in($workspace)->create();

    app(DeleteTag::class)->handle($tag, $actor);

    expect(Tag::query()->count())->toBe(0);
});

it('reports a database failure other than a duplicate name as what it is', function (): void {
    [$workspace, , $actor] = placeableProject();
    $tag = Tag::factory()->in($workspace)->named('Bug')->create();
    $tooLong = str_repeat('a', 256);

    expect(fn (): Tag => app(CreateTag::class)->handle($workspace, $actor, $tooLong))
        ->toThrow(QueryException::class, 'value too long');

    expect(fn (): Tag => app(UpdateTag::class)->handle($tag, $actor, $tooLong))
        ->toThrow(QueryException::class);

    expect(Tag::query()->pluck('name')->all())->toBe(['Bug']);
});
