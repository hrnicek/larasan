<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Events;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;

final readonly class WorkspaceInvitationAnswered
{
    public function __construct(
        public string $membershipId,
        public string $workspaceId,
        public int $userId,
        public WorkspaceMembershipStatus $answer,
    ) {}
}
