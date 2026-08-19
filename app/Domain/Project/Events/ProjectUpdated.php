<?php

declare(strict_types=1);

namespace App\Domain\Project\Events;

final readonly class ProjectUpdated
{
    /** @param list<string> $changed */
    public function __construct(
        public string $projectId,
        public array $changed,
    ) {}
}
