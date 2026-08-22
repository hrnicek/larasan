<?php

declare(strict_types=1);

namespace App\Domain\Placement\Events;

/**
 * Ids rather than models, as in every domain event here: a queued listener that
 * deserialises a model gets whatever the row looked like when the job ran.
 */
final readonly class TaskAttachedToProject
{
    public function __construct(
        public string $placementId,
        public string $taskId,
        public string $projectId,
        public int $attachedById,
    ) {}
}
