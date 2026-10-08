<?php

declare(strict_types=1);

namespace App\Domain\Section\Events;

final readonly class SectionUpdated
{
    /**
     * @param  list<string>  $changed
     */
    public function __construct(
        public string $sectionId,
        public string $projectId,
        public array $changed,
    ) {}
}
