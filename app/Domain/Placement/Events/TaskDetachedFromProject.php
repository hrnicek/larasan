<?php

declare(strict_types=1);

namespace App\Domain\Placement\Events;

final readonly class TaskDetachedFromProject
{
    public function __construct(
        public string $taskId,
        public string $projectId,
        public int $detachedById,
    ) {}
}
