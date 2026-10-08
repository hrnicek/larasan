<?php

declare(strict_types=1);

namespace App\Domain\Comment\Listeners;

use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Placement\Queries\ChannelsForTask;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use Illuminate\Contracts\Events\Dispatcher;

/** Broadcasts no comment text: a channel's audience is wider than the thread's. */
final readonly class BroadcastCommentChange
{
    public function __construct(
        private Dispatcher $events,
        private ChannelsForTask $channels,
    ) {}

    public function handle(CommentCreated $event): void
    {
        if ($event->subjectType !== 'task') {
            return;
        }

        $this->events->dispatch(new ViewInvalidated(
            ($this->channels)($event->subjectId, $event->workspaceId),
            'comment.created',
            'task',
            $event->subjectId,
            $event->authorId,
        ));
    }
}
