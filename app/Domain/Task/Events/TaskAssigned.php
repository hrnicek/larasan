<?php

declare(strict_types=1);

namespace App\Domain\Task\Events;

final readonly class TaskAssigned
{
    public function __construct(
        public string $taskId,
        public string $workspaceId,
        public ?int $assigneeId,
        public int $assignedById,
    ) {}
}
