<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum ProjectVisibility: string
{
    case Workspace = 'workspace';
    case Private = 'private';

    public function requiresExplicitMembership(): bool
    {
        return $this === self::Private;
    }
}
