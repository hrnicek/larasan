<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Events;

final readonly class WorkspaceMemberInvited
{
    public function __construct(
        public string $membershipId,
        public string $workspaceId,
        public int $userId,
        public int $invitedById,
    ) {}
}
