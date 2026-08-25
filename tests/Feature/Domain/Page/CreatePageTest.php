<?php

declare(strict_types=1);

use App\Domain\Page\Actions\CreatePage;
use App\Domain\Page\Content\PageDocument;
use App\Domain\Page\Data\CreatePageData;
use App\Domain\Page\Events\PageCreated;
use App\Domain\Page\Exceptions\PageException;
use App\Domain\Page\Models\Page;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use Illuminate\Support\Facades\Event;

it('starts an empty document at the end of the root pages', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $existing = Page::factory()->in($project)->create();

    $page = app(CreatePage::class)->handle($project, $actor, CreatePageData::titled('Brief'));

    expect($page->title)->toBe('Brief')
        ->and($page->content)->toBe(PageDocument::empty())
        ->and($page->parent_id)->toBeNull()
        ->and($page->position)->toBe($existing->position + Page::POSITION_GAP)
        ->and($page->created_by)->toBe($actor->id)
        ->and($page->version)->toBe(1);
});

it('names an untitled page rather than leaving the row blank', function (?string $given): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);

    expect(app(CreatePage::class)->handle($project, $actor, CreatePageData::titled($given))->title)
        ->toBe(Page::UNTITLED);
})->with(['nothing' => [null], 'whitespace' => ['   ']]);

it('starts a page underneath another one', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $parent = Page::factory()->in($project)->create();

    $page = app(CreatePage::class)->handle($project, $actor, CreatePageData::titled('Notes'), $parent);

    expect($page->parent_id)->toBe($parent->id)
        ->and($page->position)->toBe(Page::POSITION_GAP);
});

it('refuses a parent from another project', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $foreign = Page::factory()->create();

    expect(fn (): Page => app(CreatePage::class)->handle($project, $actor, CreatePageData::titled('Notes'), $foreign))
        ->toThrow(PageException::class, 'not in this project');
});

it('refuses to nest deeper than a tree can be drawn', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);

    $parent = Page::factory()->in($project)->create();

    for ($level = 1; $level < Page::MAX_DEPTH; $level++) {
        $parent = Page::factory()->under($parent)->create();
    }

    expect(fn (): Page => app(CreatePage::class)->handle($project, $actor, CreatePageData::titled('Deep'), $parent))
        ->toThrow(PageException::class, 'nested any deeper');
});

it('refuses a viewer, whatever the UI rendered', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);

    expect(fn (): Page => app(CreatePage::class)->handle($project, $actor, CreatePageData::titled('Brief')))
        ->toThrow(PageException::class, 'do not have permission');

    expect(Page::query()->count())->toBe(0);
});

it('refuses somebody from another workspace holding the project', function (): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    [, $stranger] = workspaceWith(WorkspaceRole::Owner);

    expect(fn (): Page => app(CreatePage::class)->handle($project, $stranger, CreatePageData::titled('Brief')))
        ->toThrow(PageException::class);
});

it('tells the project a page appeared', function (): void {
    Event::fake([PageCreated::class]);

    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = app(CreatePage::class)->handle($project, $actor, CreatePageData::titled('Brief'));

    Event::assertDispatched(PageCreated::class, fn (PageCreated $event): bool => $event->pageId === $page->id
        && $event->projectId === $project->id
        && $event->createdById === $actor->id);
});
