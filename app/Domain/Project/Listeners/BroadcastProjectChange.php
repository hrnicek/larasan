<?php

declare(strict_types=1);

namespace App\Domain\Project\Listeners;

use App\Domain\Project\Events\ProjectArchived;
use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Queries\ChannelsForProject;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The project itself changed — its name, its description, or whether it is archived.
 *
 * `ProjectCreated` is deliberately absent: a project is created by one person who is already
 * looking at the answer, and announcing a private one would put its id on the workspace
 * channel before anybody has been given it.
 */
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
