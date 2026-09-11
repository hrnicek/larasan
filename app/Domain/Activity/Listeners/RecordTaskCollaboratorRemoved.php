<?php

declare(strict_types=1);

namespace App\Domain\Activity\Listeners;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Shared\Enums\ActivityType;
use App\Domain\Task\Events\TaskCollaboratorRemoved;
use App\Domain\Task\Models\Task;

final readonly class RecordTaskCollaboratorRemoved
{
    public function __construct(private RecordActivity $record) {}

    public function handle(TaskCollaboratorRemoved $event): void
    {
        $task = Task::query()->find($event->taskId);

        if ($task === null) {
            return;
        }

        $this->record->handle(
            $event->workspaceId,
            $task,
            $event->removedById,
            ActivityType::TaskCollaboratorRemoved,
            ['collaborator_id' => $event->collaboratorId],
        );
    }
}
