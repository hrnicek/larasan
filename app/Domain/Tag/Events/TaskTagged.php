<?php

declare(strict_types=1);

namespace App\Domain\Tag\Events;

final readonly class TaskTagged
{
    public function __construct(
        public string $taskId,
        public string $tagId,
        public string $workspaceId,
        public int $taggedById,
    ) {}
}
