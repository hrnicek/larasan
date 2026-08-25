<?php

declare(strict_types=1);

namespace App\Domain\Page\Events;

final readonly class PageDeleted
{
    /**
     * @param  list<string>  $descendantIds  the subtree that went with it, so a screen holding
     *                                       any of them knows it is looking at something gone
     */
    public function __construct(
        public string $pageId,
        public string $projectId,
        public int $deletedById,
        public array $descendantIds = [],
    ) {}
}
