<?php

declare(strict_types=1);

namespace App\Domain\Project\Listeners;

use App\Domain\Project\Events\ProjectArchived;
use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Queries\ChannelsForProject;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class BroadcastProjectChange
{
    public function __construct(
        private Dispatcher $events,
        private ChannelsForProject $channels,
    ) {}

    public function handle(ProjectUpdated|ProjectArchived $event): void
    {
        $change = match ($event::class) {
            ProjectUpdated::class => 'project.updated',
            ProjectArchived::class => $event->archived ? 'project.archived' : 'project.restored',
        };

        $this->events->dispatch(new ViewInvalidated(
            ($this->channels)($event->projectId),
            $change,
            'project',
            $event->projectId,
            $event instanceof ProjectArchived ? $event->archivedById : null,
        ));
    }
}
