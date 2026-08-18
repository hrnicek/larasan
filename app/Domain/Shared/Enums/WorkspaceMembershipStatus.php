<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum WorkspaceMembershipStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Declined = 'declined';

    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
