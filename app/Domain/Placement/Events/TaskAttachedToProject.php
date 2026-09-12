<?php

declare(strict_types=1);

namespace App\Domain\Placement\Events;

final readonly class TaskAttachedToProject
{
    public function __construct(
        public string $placementId,
        public string $taskId,
        public string $projectId,
        public int $attachedById,
    ) {}
}
