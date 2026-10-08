<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Data;

use App\Domain\Shared\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;

final readonly class InviteWorkspaceMemberData
{
    public string $email;

    public function __construct(
        string $email,
        public WorkspaceRole $role,
        public ?CarbonImmutable $expiresAt = null,
    ) {
        // workspace_memberships_email_lowercase_check rejects a non-normalised address.
        $this->email = mb_strtolower(trim($email));
    }

    public function expiresAt(): CarbonImmutable
    {
        return $this->expiresAt ?? CarbonImmutable::now()->addWeek();
    }
}
