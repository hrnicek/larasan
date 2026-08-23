<?php

declare(strict_types=1);

namespace App\Domain\Section\Listeners;

use App\Domain\Section\Events\SectionCreated;
use App\Domain\Section\Events\SectionDeleted;
use App\Domain\Section\Events\SectionMoved;
use App\Domain\Section\Events\SectionUpdated;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * A column appeared, was renamed, moved or removed. A section is part of the board and
 * nothing outside it, so only the project's own channel hears about it — including for a
 * project the workspace can see, whose sidebar entry does not change when a column does.
 */
final readonly class BroadcastSectionChange
{
    public function __construct(private Dispatcher $events) {}

    public function handle(SectionCreated|SectionUpdated|SectionMoved|SectionDeleted $event): void
    {
        $change = match ($event::class) {
            SectionCreated::class => 'section.created',
            SectionUpdated::class => 'section.updated',
            SectionMoved::class => 'section.moved',
            SectionDeleted::class => 'section.deleted',
        };

        $actorId = match ($event::class) {
            SectionCreated::class => $event->createdById,
            SectionDeleted::class => $event->deletedById,
            SectionUpdated::class, SectionMoved::class => null,
        };

        $this->events->dispatch(new ViewInvalidated(
            ["project.{$event->projectId}"],
            $change,
            'section',
            $event->sectionId,
            $actorId,
        ));
    }
}
