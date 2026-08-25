<?php

declare(strict_types=1);

use App\Domain\Page\Actions\SavePageContent;
use App\Domain\Page\Data\SavePageContentData;
use App\Domain\Page\Events\PageUpdated;
use App\Domain\Page\Exceptions\PageException;
use App\Domain\Page\Models\Page;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use Illuminate\Support\Facades\Event;

it('writes the document and moves the version on', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create(['version' => 1]);

    $saved = app(SavePageContent::class)->handle($page, $actor, new SavePageContentData(
        content: doc([['type' => 'paragraph', 'content' => [textNode('The brief')]]]),
        version: 1,
    ));

    expect($saved->version)->toBe(2)
        ->and($saved->content['content'][0]['content'][0]['text'])->toBe('The brief')
        ->and($saved->excerpt)->toBe('The brief')
        ->and($saved->updated_by)->toBe($actor->id);
});

it('reduces the document to what this application draws before writing it', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();

    $saved = app(SavePageContent::class)->handle($page, $actor, new SavePageContentData(
        content: doc([
            ['type' => 'script', 'content' => [textNode('alert(1)')]],
            ['type' => 'paragraph', 'content' => [
                textNode('there', [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]),
            ]],
        ]),
        version: $page->version,
    ));

    expect($saved->content['content'])->toBe([
        textNode('alert(1)'),
        ['type' => 'paragraph', 'content' => [textNode('there')]],
    ]);
});

it('refuses a save that was written against an older version', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create(['version' => 4]);

    expect(fn (): Page => app(SavePageContent::class)->handle($page, $actor, new SavePageContentData(
        content: doc([['type' => 'paragraph', 'content' => [textNode('mine')]]]),
        version: 3,
    )))->toThrow(PageException::class, 'changed somewhere else');

    expect($page->fresh()?->version)->toBe(4)
        ->and($page->fresh()?->content)->toEqual($page->content);
});

it('refuses a save that claims a version from the future', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create(['version' => 2]);

    expect(fn (): Page => app(SavePageContent::class)->handle($page, $actor, new SavePageContentData(
        content: doc([]),
        version: 9,
    )))->toThrow(PageException::class, 'changed somewhere else');
});

it('leaves the row untouched when the document is refused', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();

    expect(fn (): Page => app(SavePageContent::class)->handle($page, $actor, new SavePageContentData(
        content: ['type' => 'paragraph'],
        version: $page->version,
    )))->toThrow(PageException::class, 'does not look like a page document');

    expect($page->fresh()?->version)->toBe(1);
});

it('has no excerpt for a page emptied back out', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create(['excerpt' => 'Something']);

    $saved = app(SavePageContent::class)->handle($page, $actor, new SavePageContentData(
        content: doc([]),
        version: $page->version,
    ));

    expect($saved->excerpt)->toBeNull();
});

it('refuses a commenter and a viewer', function (ProjectAccessLevel $access): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, $access);
    $page = Page::factory()->in($project)->create();

    expect(fn (): Page => app(SavePageContent::class)->handle($page, $actor, new SavePageContentData(
        content: doc([['type' => 'paragraph', 'content' => [textNode('mine')]]]),
        version: $page->version,
    )))->toThrow(PageException::class, 'do not have permission');
})->with([
    'commenter' => [ProjectAccessLevel::Commenter],
    'viewer' => [ProjectAccessLevel::Viewer],
]);

it('refuses somebody from another workspace holding a valid page', function (): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();
    [, $stranger] = workspaceWith(WorkspaceRole::Owner);

    expect(fn (): Page => app(SavePageContent::class)->handle($page, $stranger, new SavePageContentData(
        content: doc([]),
        version: $page->version,
    )))->toThrow(PageException::class);
});

it('tells the project the page was written in', function (): void {
    Event::fake([PageUpdated::class]);

    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();

    app(SavePageContent::class)->handle($page, $actor, new SavePageContentData(
        content: doc([]),
        version: $page->version,
    ));

    Event::assertDispatched(PageUpdated::class, fn (PageUpdated $event): bool => $event->pageId === $page->id
        && $event->projectId === $project->id
        && $event->updatedById === $actor->id);
});
