<?php

declare(strict_types=1);

namespace App\Domain\Activity\Listeners;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Shared\Enums\ActivityType;
use App\Domain\Task\Events\TaskCompleted;
use App\Domain\Task\Models\Task;

final readonly class RecordTaskCompleted
{
    public function __construct(private RecordActivity $record) {}

    public function handle(TaskCompleted $event): void
    {
        $task = Task::query()->find($event->taskId);

        if ($task === null) {
            return;
        }

        $this->record->handle($event->workspaceId, $task, $event->completedById, ActivityType::TaskCompleted);
    }
}
