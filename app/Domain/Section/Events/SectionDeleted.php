<?php

declare(strict_types=1);

namespace App\Domain\Section\Events;

final readonly class SectionDeleted
{
    public function __construct(
        public string $sectionId,
        public string $projectId,
        public int $deletedById,
    ) {}
}
