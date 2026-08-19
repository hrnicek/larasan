<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Events;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;

/**
 * One event for both answers, carrying which one it was. Listeners that care about
 * acceptance and refusal usually care about both — a member count, an activity entry, a
 * notification to the inviter — and two events would make every one of them subscribe
 * twice.
 */
final readonly class WorkspaceInvitationAnswered
{
    public function __construct(
        public string $membershipId,
        public string $workspaceId,
        public int $userId,
        public WorkspaceMembershipStatus $answer,
    ) {}
}
