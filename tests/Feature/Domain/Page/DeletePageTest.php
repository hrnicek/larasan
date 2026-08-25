<?php

declare(strict_types=1);

use App\Domain\Page\Actions\DeletePage;
use App\Domain\Page\Events\PageDeleted;
use App\Domain\Page\Exceptions\PageException;
use App\Domain\Page\Models\Page;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use Illuminate\Support\Facades\Event;

it('removes a page', function (): void {
    [$project, $actor] = projectWithWriter();
    $page = Page::factory()->in($project)->create();

    app(DeletePage::class)->handle($page, $actor);

    expect(Page::query()->count())->toBe(0)
        ->and(Page::withTrashed()->count())->toBe(1);
});

it('removes what was written underneath it', function (): void {
    [$project, $actor] = projectWithWriter();
    $page = Page::factory()->in($project)->create();
    $child = Page::factory()->under($page)->create();
    $grandchild = Page::factory()->under($child)->create();
    $untouched = Page::factory()->in($project)->create();

    app(DeletePage::class)->handle($page, $actor);

    expect(Page::query()->pluck('id')->all())->toBe([$untouched->id])
        ->and($child->fresh()?->deleted_at)->not->toBeNull()
        ->and($grandchild->fresh()?->deleted_at)->not->toBeNull();
});

it('names the subtree it took with it', function (): void {
    Event::fake([PageDeleted::class]);

    [$project, $actor] = projectWithWriter();
    $page = Page::factory()->in($project)->create();
    $child = Page::factory()->under($page)->create();

    app(DeletePage::class)->handle($page, $actor);

    Event::assertDispatched(PageDeleted::class, fn (PageDeleted $event): bool => $event->pageId === $page->id
        && $event->descendantIds === [$child->id]
        && $event->deletedById === $actor->id);
});

it('refuses an editor without the delete capability of a viewer', function (): void {
    [$project, $actor] = projectWithWriter(ProjectAccessLevel::Viewer);
    $page = Page::factory()->in($project)->create();

    expect(fn () => app(DeletePage::class)->handle($page, $actor))
        ->toThrow(PageException::class, 'do not have permission');

    expect(Page::query()->count())->toBe(1);
});

it('refuses somebody from another workspace holding a valid id', function (): void {
    [$project] = projectWithWriter();
    $page = Page::factory()->in($project)->create();
    [, $stranger] = workspaceWith(WorkspaceRole::Owner);

    expect(fn () => app(DeletePage::class)->handle($page, $stranger))->toThrow(PageException::class);

    expect(Page::query()->count())->toBe(1);
});
