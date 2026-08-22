<?php

declare(strict_types=1);

namespace App\Domain\Activity\Listeners;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Shared\Enums\ActivityType;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Models\Task;

final readonly class RecordTaskAssigned
{
    public function __construct(private RecordActivity $record) {}

    public function handle(TaskAssigned $event): void
    {
        $task = Task::query()->find($event->taskId);

        if ($task === null) {
            return;
        }

        // Null is the whole point of carrying it: unassigning is something that happened, and
        // a line that omitted the field would be indistinguishable from one nobody recorded.
        $this->record->handle(
            $event->workspaceId,
            $task,
            $event->assignedById,
            ActivityType::TaskAssigned,
            ['assignee_id' => $event->assigneeId],
        );
    }
}
