<?php

declare(strict_types=1);

use App\Domain\Page\Events\PageCreated;
use App\Domain\Page\Events\PageDeleted;
use App\Domain\Page\Events\PageMoved;
use App\Domain\Page\Events\PageUpdated;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use Illuminate\Support\Facades\Event;

it('tells the project a page changed, and nobody else', function (
    PageCreated|PageUpdated|PageMoved|PageDeleted $event,
    string $change,
): void {
    Event::fake([ViewInvalidated::class]);

    event($event);

    Event::assertDispatched(
        ViewInvalidated::class,
        fn (ViewInvalidated $broadcast): bool => $broadcast->change === $change
            && $broadcast->subjectType === 'page'
            && $broadcast->subjectId === 'page-1'
            && $broadcast->channels === ['project.project-1'],
    );
})->with([
    'created' => [fn (): PageCreated => new PageCreated('page-1', 'project-1', 7), 'page.created'],
    'updated' => [fn (): PageUpdated => new PageUpdated('page-1', 'project-1', 7), 'page.updated'],
    'moved' => [fn (): PageMoved => new PageMoved('page-1', 'project-1', null), 'page.moved'],
    'deleted' => [fn (): PageDeleted => new PageDeleted('page-1', 'project-1', 7), 'page.deleted'],
]);

it('leaves the actor out of a move, because nobody performed it alone', function (): void {
    Event::fake([ViewInvalidated::class]);

    event(new PageMoved('page-1', 'project-1', 'parent-1'));

    Event::assertDispatched(ViewInvalidated::class, fn (ViewInvalidated $broadcast): bool => $broadcast->actorId === null);
});
