<?php

declare(strict_types=1);

namespace App\Domain\Page\Listeners;

use App\Domain\Page\Events\PageCreated;
use App\Domain\Page\Events\PageDeleted;
use App\Domain\Page\Events\PageMoved;
use App\Domain\Page\Events\PageUpdated;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * A page appeared, was written in, moved or removed. A page belongs to one project and nothing
 * outside it, so only that project's channel hears about it.
 */
final readonly class BroadcastPageChange
{
    public function __construct(private Dispatcher $events) {}

    public function handle(PageCreated|PageUpdated|PageMoved|PageDeleted $event): void
    {
        $change = match ($event::class) {
            PageCreated::class => 'page.created',
            PageUpdated::class => 'page.updated',
            PageMoved::class => 'page.moved',
            PageDeleted::class => 'page.deleted',
        };

        $actorId = match ($event::class) {
            PageCreated::class => $event->createdById,
            PageUpdated::class => $event->updatedById,
            PageDeleted::class => $event->deletedById,
            PageMoved::class => null,
        };

        $this->events->dispatch(new ViewInvalidated(
            ["project.{$event->projectId}"],
            $change,
            'page',
            $event->pageId,
            $actorId,
        ));
    }
}
