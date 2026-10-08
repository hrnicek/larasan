<?php

declare(strict_types=1);

namespace App\Domain\Task\Events;

final readonly class TaskCollaboratorAdded
{
    public function __construct(
        public string $taskId,
        public string $workspaceId,
        public int $collaboratorId,
        public int $addedById,
    ) {}
}
