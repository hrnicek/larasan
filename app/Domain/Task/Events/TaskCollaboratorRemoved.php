<?php

declare(strict_types=1);

namespace App\Domain\Task\Events;

final readonly class TaskCollaboratorRemoved
{
    public function __construct(
        public string $taskId,
        public string $workspaceId,
        public int $collaboratorId,
        /** The collaborator themselves when they stepped off. */
        public int $removedById,
    ) {}
}
