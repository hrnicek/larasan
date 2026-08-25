<?php

declare(strict_types=1);

use App\Domain\Page\Actions\MovePage;
use App\Domain\Page\Events\PageMoved;
use App\Domain\Page\Exceptions\PageException;
use App\Domain\Page\Models\Page;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use Illuminate\Support\Facades\Event;

/**
 * @return list<string>
 */
function rootOrder(string $projectId): array
{
    /** @var list<string> $ids */
    $ids = Page::query()
        ->where('project_id', $projectId)
        ->whereNull('parent_id')
        ->orderBy('position')
        ->pluck('id')
        ->values()
        ->all();

    return $ids;
}

it('puts a page behind one of its siblings', function (): void {
    [$project, $actor] = projectWithWriter();
    $first = Page::factory()->in($project)->at(SparsePosition::GAP)->create();
    $second = Page::factory()->in($project)->at(SparsePosition::GAP * 2)->create();
    $third = Page::factory()->in($project)->at(SparsePosition::GAP * 3)->create();

    app(MovePage::class)->handle($third, $actor, null, $first);

    expect(rootOrder($project->id))->toBe([$first->id, $third->id, $second->id]);
});

it('puts a page at the front when there is nothing to follow', function (): void {
    [$project, $actor] = projectWithWriter();
    $first = Page::factory()->in($project)->at(SparsePosition::GAP)->create();
    $second = Page::factory()->in($project)->at(SparsePosition::GAP * 2)->create();

    app(MovePage::class)->handle($second, $actor, null, null);

    expect(rootOrder($project->id))->toBe([$second->id, $first->id]);
});

it('moves a page inside another one', function (): void {
    [$project, $actor] = projectWithWriter();
    $parent = Page::factory()->in($project)->create();
    $page = Page::factory()->in($project)->create();

    $moved = app(MovePage::class)->handle($page, $actor, $parent, null);

    expect($moved->parent_id)->toBe($parent->id)
        ->and($moved->position)->toBe(SparsePosition::GAP);
});

it('moves a page back out to the root', function (): void {
    [$project, $actor] = projectWithWriter();
    $parent = Page::factory()->in($project)->create();
    $child = Page::factory()->under($parent)->create();

    expect(app(MovePage::class)->handle($child, $actor, null, $parent)->parent_id)->toBeNull();
});

it('takes the subtree with the page that moves', function (): void {
    [$project, $actor] = projectWithWriter();
    $parent = Page::factory()->in($project)->create();
    $page = Page::factory()->in($project)->create();
    $child = Page::factory()->under($page)->create();

    app(MovePage::class)->handle($page, $actor, $parent, null);

    expect($child->fresh()?->parent_id)->toBe($page->id)
        ->and($page->fresh()?->parent_id)->toBe($parent->id);
});

it('refuses to move a page inside itself', function (): void {
    [$project, $actor] = projectWithWriter();
    $page = Page::factory()->in($project)->create();

    expect(fn (): Page => app(MovePage::class)->handle($page, $actor, $page, null))
        ->toThrow(PageException::class, 'inside itself');
});

it('refuses to move a page inside its own child', function (): void {
    [$project, $actor] = projectWithWriter();
    $page = Page::factory()->in($project)->create();
    $child = Page::factory()->under($page)->create();
    $grandchild = Page::factory()->under($child)->create();

    expect(fn (): Page => app(MovePage::class)->handle($page, $actor, $grandchild, null))
        ->toThrow(PageException::class, 'inside itself');
});

it('refuses a parent in another project', function (): void {
    [$project, $actor] = projectWithWriter();
    $page = Page::factory()->in($project)->create();

    expect(fn (): Page => app(MovePage::class)->handle($page, $actor, Page::factory()->create(), null))
        ->toThrow(PageException::class, 'not in this project');
});

it('refuses a move that would put the subtree past the depth limit', function (): void {
    [$project, $actor] = projectWithWriter();

    $parent = Page::factory()->in($project)->create();

    for ($level = 1; $level < Page::MAX_DEPTH; $level++) {
        $parent = Page::factory()->under($parent)->create();
    }

    $page = Page::factory()->in($project)->create();
    Page::factory()->under($page)->create();

    expect(fn (): Page => app(MovePage::class)->handle($page, $actor, $parent, null))
        ->toThrow(PageException::class, 'nested any deeper');
});

it('refuses an anchor that is not where the page is going', function (): void {
    [$project, $actor] = projectWithWriter();
    $parent = Page::factory()->in($project)->create();
    $elsewhere = Page::factory()->under($parent)->create();
    $page = Page::factory()->in($project)->create();

    expect(fn (): Page => app(MovePage::class)->handle($page, $actor, null, $elsewhere))
        ->toThrow(PageException::class, 'not where this one would be placed');
});

it('refuses a page as its own anchor', function (): void {
    [$project, $actor] = projectWithWriter();
    $page = Page::factory()->in($project)->create();

    expect(fn (): Page => app(MovePage::class)->handle($page, $actor, null, $page))
        ->toThrow(PageException::class, 'not where this one would be placed');
});

it('respreads a level whose neighbours have closed up', function (): void {
    [$project, $actor] = projectWithWriter();
    $first = Page::factory()->in($project)->at(10)->create();
    $second = Page::factory()->in($project)->at(11)->create();
    $page = Page::factory()->in($project)->at(SparsePosition::GAP)->create();

    app(MovePage::class)->handle($page, $actor, null, $first);

    expect(rootOrder($project->id))->toBe([$first->id, $page->id, $second->id])
        ->and(Page::query()->where('project_id', $project->id)->pluck('position')->every(fn (int $position): bool => $position > 0))
        ->toBeTrue();
});

it('refuses a viewer', function (): void {
    [$project, $actor] = projectWithWriter(ProjectAccessLevel::Viewer);
    $page = Page::factory()->in($project)->create();

    expect(fn (): Page => app(MovePage::class)->handle($page, $actor, null, null))
        ->toThrow(PageException::class, 'do not have permission');
});

it('refuses somebody from another workspace', function (): void {
    [$project] = projectWithWriter();
    $page = Page::factory()->in($project)->create();
    [, $stranger] = workspaceWith(WorkspaceRole::Owner);

    expect(fn (): Page => app(MovePage::class)->handle($page, $stranger, null, null))
        ->toThrow(PageException::class);
});

it('tells the project where the page went', function (): void {
    Event::fake([PageMoved::class]);

    [$project, $actor] = projectWithWriter();
    $parent = Page::factory()->in($project)->create();
    $page = Page::factory()->in($project)->create();

    app(MovePage::class)->handle($page, $actor, $parent, null);

    Event::assertDispatched(PageMoved::class, fn (PageMoved $event): bool => $event->pageId === $page->id
        && $event->parentId === $parent->id);
});
