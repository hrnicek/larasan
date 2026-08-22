<?php

declare(strict_types=1);

namespace App\Domain\Section\Events;

final readonly class SectionMoved
{
    public function __construct(
        public string $sectionId,
        public string $projectId,
        /** The section it now follows, or null when it went to the front. */
        public ?string $afterSectionId,
    ) {}
}
