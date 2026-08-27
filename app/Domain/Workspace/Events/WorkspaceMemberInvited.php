<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Events;

final readonly class WorkspaceMemberInvited
{
    public function __construct(
        public string $membershipId,
        public string $workspaceId,
        /** Null while the address has no account: an invitation names a person only once one exists. */
        public ?int $userId,
        public string $email,
        public int $invitedById,
    ) {}
}
