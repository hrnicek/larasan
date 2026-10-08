<?php

declare(strict_types=1);

namespace App\Domain\Page\Events;

final readonly class PageDeleted
{
    /**
     * @param  list<string>  $descendantIds
     */
    public function __construct(
        public string $pageId,
        public string $projectId,
        public int $deletedById,
        public array $descendantIds = [],
    ) {}
}
