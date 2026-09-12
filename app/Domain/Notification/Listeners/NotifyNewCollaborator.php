<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Notifications\TaskCollaboratorAddedNotification;
use App\Domain\Task\Events\TaskCollaboratorAdded;
use App\Domain\Task\Models\TaskCollaborator;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

final readonly class NotifyNewCollaborator implements ShouldQueue
{
    public function viaQueue(): string
    {
        return 'notifications';
    }

    public function handle(TaskCollaboratorAdded $event): void
    {
        if ($event->collaboratorId === $event->addedById) {
            return;
        }

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
