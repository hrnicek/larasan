<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Events;

final readonly class WorkspaceUpdated
{
    /** @param list<string> $changed */
    public function __construct(
        public string $workspaceId,
        public array $changed,
    ) {}
}
