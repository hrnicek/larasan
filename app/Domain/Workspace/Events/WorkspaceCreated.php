<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Events;

final readonly class WorkspaceCreated
{
    public function __construct(
        public string $workspaceId,
        public int $ownerId,
    ) {}
}
