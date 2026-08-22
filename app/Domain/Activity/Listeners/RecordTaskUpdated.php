<?php

declare(strict_types=1);

namespace App\Domain\Activity\Listeners;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Shared\Enums\ActivityType;
use App\Domain\Task\Events\TaskUpdated;
use App\Domain\Task\Models\Task;

final readonly class RecordTaskUpdated
{
    public function __construct(private RecordActivity $record) {}

    public function handle(TaskUpdated $event): void
    {
        $task = Task::query()->find($event->taskId);

        if ($task === null) {
            return;
        }

        /*
         * The field names, not their values. A history line says a description was changed;
         * showing what it used to say is a different feature with a different storage cost,
         * and guessing at it here would put a copy of every task's text in this table.
         */
        $this->record->handle(
            $event->workspaceId,
            $task,
            $event->updatedById,
            ActivityType::TaskUpdated,
            ['changed' => $event->changed],
        );
    }
}
