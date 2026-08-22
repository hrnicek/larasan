<?php

declare(strict_types=1);

namespace App\Domain\Task\Events;

/**
 * Ids rather than models, as in every task event here.
 */
final readonly class TaskFollowed
{
    public function __construct(
        public string $taskId,
        public string $workspaceId,
        public int $followerId,
    ) {}
}
