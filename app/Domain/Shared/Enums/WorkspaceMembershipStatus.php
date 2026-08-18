<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Declined is the invitee's decision, Revoked the inviter's, Expired the clock's. They
 * are distinct because TASK-030-005 must reject accepting a revoked or expired
 * invitation, and because these values are persisted — adding a case later is a data
 * migration.
 */
enum WorkspaceMembershipStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Declined = 'declined';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }

    public function canBeAccepted(): bool
    {
        return $this === self::Invited;
    }
}
