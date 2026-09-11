<?php

declare(strict_types=1);

namespace App\Domain\Task\Listeners;

use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Events\TaskCollaboratorAdded;
use App\Domain\Task\Models\Task;
use App\Models\User;

/**
 * Working on a task means watching it, for a collaborator as for an assignee
 * (`FollowAssignedTask`). Being taken off removes nothing, for the same reason.
 */
final readonly class FollowCollaboratedTask
{
    public function __construct(private FollowTask $follow) {}

    public function handle(TaskCollaboratorAdded $event): void
    {
        $task = Task::query()->find($event->taskId);
        $collaborator = User::query()->find($event->collaboratorId);

        if ($task === null || $collaborator === null) {
            return;
        }

        $this->follow->handle($task, $collaborator);
    }
}
