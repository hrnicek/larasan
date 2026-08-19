<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Events;

/**
 * Carries ids rather than models: listeners are queued, run after commit, and must read
 * the current row rather than a snapshot serialised at dispatch time.
 */
final readonly class WorkspaceCreated
{
    public function __construct(
        public string $workspaceId,
        public int $ownerId,
    ) {}
}
