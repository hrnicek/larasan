<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Checked in addition to the WorkspaceRole capability; both must pass. See ADR-0006.
 */
enum ProjectAccessLevel: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Commenter = 'commenter';
    case Viewer = 'viewer';

    /**
     * Owner is excluded: it is only ever granted through an explicit project membership.
     *
     * @return list<self>
     */
    public static function grantableByDefault(): array
    {
        return [self::Editor, self::Commenter, self::Viewer];
    }

    /**
     * @return list<string>
     */
    public static function editingValues(): array
    {
        return array_values(array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => $case->canEdit()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function commentingValues(): array
    {
        return array_values(array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => $case->canComment()),
        ));
    }

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
