<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum ProjectVisibility: string
{
    case Workspace = 'workspace';
    case Private = 'private';

    /**
     * A workspace-visible project grants read access to workspace members without an
     * explicit project_memberships row; a private project requires one (ADR-0006).
     */
    public function requiresExplicitMembership(): bool
    {
        return $this === self::Private;
    }
}
