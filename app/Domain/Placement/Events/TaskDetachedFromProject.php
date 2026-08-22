<?php

declare(strict_types=1);

namespace App\Domain\Placement\Events;

/**
 * The card is gone; the task is not. Ids rather than models, as in every domain event here.
 */
final readonly class TaskDetachedFromProject
{
    public function __construct(
        public string $taskId,
        public string $projectId,
        public int $detachedById,
    ) {}
}
