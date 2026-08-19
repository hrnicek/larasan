<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Data;

use App\Domain\Shared\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;

final readonly class InviteWorkspaceMemberData
{
    public function __construct(
        public int $userId,
        public WorkspaceRole $role,
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    /**
     * A week is the default an inviter does not have to think about. It is here rather
     * than in the migration's column default so the value is visible to the caller and
     * can be overridden per invitation.
     */
    public function expiresAt(): CarbonImmutable
    {
        return $this->expiresAt ?? CarbonImmutable::now()->addWeek();
    }
}
