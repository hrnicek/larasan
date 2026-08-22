<?php

declare(strict_types=1);

namespace App\Domain\Activity\Listeners;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Placement\Events\TaskDetachedFromProject;
use App\Domain\Shared\Enums\ActivityType;
use App\Domain\Task\Models\Task;

final readonly class RecordTaskDetachedFromProject
{
    public function __construct(private RecordActivity $record) {}

    public function handle(TaskDetachedFromProject $event): void
    {
        $task = Task::query()->find($event->taskId);

        if ($task === null) {
            return;
        }

        $this->record->handle(
            $task->workspace_id,
            $task,
            $event->detachedById,
            ActivityType::TaskDetachedFromProject,
            ['project_id' => $event->projectId],
        );
    }
}
