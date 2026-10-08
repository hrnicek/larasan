<?php

declare(strict_types=1);

namespace App\Domain\Project\Events;

final readonly class ProjectArchived
{
    public function __construct(
        public string $projectId,
        public int $archivedById,
        public bool $archived,
    ) {}
}
