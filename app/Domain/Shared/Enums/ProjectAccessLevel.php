<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Per-project access (ADR-0006). Independent of WorkspaceRole: a workspace Member may
 * hold the ProjectUpdate capability and still only view a project they were added to
 * as a Viewer. Both checks must pass.
 */
enum ProjectAccessLevel: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Commenter = 'commenter';
    case Viewer = 'viewer';

    public function canManageProject(): bool
    {
        return $this === self::Owner;
    }

    public function canEdit(): bool
    {
        return match ($this) {
            self::Owner, self::Editor => true,
            self::Commenter, self::Viewer => false,
        };
    }

    public function canComment(): bool
    {
        return match ($this) {
            self::Owner, self::Editor, self::Commenter => true,
            self::Viewer => false,
        };
    }
}
