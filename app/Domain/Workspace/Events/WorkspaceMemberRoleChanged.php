<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Events;

use App\Domain\Shared\Enums\WorkspaceRole;

final readonly class WorkspaceMemberRoleChanged
{
    public function __construct(
        public string $membershipId,
        public string $workspaceId,
        /** Null while the invited address has no account. */
        public ?int $userId,
        public WorkspaceRole $from,
        public WorkspaceRole $to,
    ) {}
}
