<?php

declare(strict_types=1);

namespace App\Domain\Task\Events;

/**
 * Ids rather than models, in every task event: a queued listener that deserialises a model
 * gets whatever the row looked like when the job ran, and the difference only shows up
 * under load.
 */
final readonly class TaskCreated
{
    public function __construct(
        public string $taskId,
        public string $workspaceId,
        public int $createdById,
    ) {}
}
