<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Data;

use App\Domain\Shared\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;

final readonly class InviteWorkspaceMemberData
{
    /**
     * The address, not an account id: whether somebody has registered under it is a fact
     * about the world, and it can change between this invitation being sent and being
     * answered. The Action resolves the account if there is one.
     */
    public string $email;

    public function __construct(
        string $email,
        public WorkspaceRole $role,
        public ?CarbonImmutable $expiresAt = null,
    ) {
        // Normalised here rather than at every call site, and the column has a CHECK that
        // catches the row which arrived some other way.
        $this->email = mb_strtolower(trim($email));
    }

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
