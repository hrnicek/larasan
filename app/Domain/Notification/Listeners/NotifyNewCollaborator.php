<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Notifications\TaskCollaboratorAddedNotification;
use App\Domain\Task\Events\TaskCollaboratorAdded;
use App\Domain\Task\Models\TaskCollaborator;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tell the person put on a task, the way `NotifyAssignee` tells the one it was given to.
 *
 * Nobody is told about their own doing, and being taken off tells nobody.
 */
final readonly class NotifyNewCollaborator implements ShouldQueue
{
    /** Below `broadcasts`, as every inbox notice is (ADR-0008). */
    public function viaQueue(): string
    {
        return 'notifications';
    }

    public function handle(TaskCollaboratorAdded $event): void
    {
        if ($event->collaboratorId === $event->addedById) {
            return;
        }

        // Queued, so the world may have moved on: somebody taken off again before this ran has
        // nothing left to hear about.
        $stillOnTask = TaskCollaborator::query()
            ->where('task_id', $event->taskId)
            ->where('user_id', $event->collaboratorId)
            ->exists();

        if (! $stillOnTask) {
            return;
        }

        User::query()->find($event->collaboratorId)?->notify(new TaskCollaboratorAddedNotification(
            $event->taskId,
            $event->workspaceId,
            $event->addedById,
        ));
    }
}
