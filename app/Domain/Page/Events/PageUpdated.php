<?php

declare(strict_types=1);

namespace App\Domain\Page\Events;

final readonly class PageUpdated
{
    public function __construct(
        public string $pageId,
        public string $projectId,
        public int $updatedById,
    ) {}
}
