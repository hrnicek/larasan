<?php

declare(strict_types=1);

namespace App\Domain\Project\Events;

final readonly class ProjectCreated
{
    public function __construct(
        public string $projectId,
        public string $workspaceId,
        public int $createdById,
    ) {}
}
