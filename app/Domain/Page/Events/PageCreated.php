<?php

declare(strict_types=1);

namespace App\Domain\Page\Events;

final readonly class PageCreated
{
    public function __construct(
        public string $pageId,
        public string $projectId,
        public int $createdById,
    ) {}
}
