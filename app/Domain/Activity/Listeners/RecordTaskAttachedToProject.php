<?php

declare(strict_types=1);

namespace App\Domain\Activity\Listeners;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Placement\Events\TaskAttachedToProject;
use App\Domain\Shared\Enums\ActivityType;
use App\Domain\Task\Models\Task;

final readonly class RecordTaskAttachedToProject
{
    public function __construct(private RecordActivity $record) {}

    public function handle(TaskAttachedToProject $event): void
    {
        $task = Task::query()->find($event->taskId);

        if ($task === null) {
            return;
        }

        $this->record->handle(
            $task->workspace_id,
            $task,
            $event->attachedById,
            ActivityType::TaskAttachedToProject,
            ['project_id' => $event->projectId],
        );
    }
}
