<?php

declare(strict_types=1);

namespace App\Domain\Task\Listeners;

use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Models\Task;
use App\Models\User;

final readonly class FollowAssignedTask
{
    public function __construct(private FollowTask $follow) {}

    public function handle(TaskAssigned $event): void
    {
        if ($event->assigneeId === null) {
            return;
        }

        $task = Task::query()->find($event->taskId);
        $assignee = User::query()->find($event->assigneeId);

        if ($task === null || $assignee === null) {
            return;
        }

        $this->follow->handle($task, $assignee);
    }
}
