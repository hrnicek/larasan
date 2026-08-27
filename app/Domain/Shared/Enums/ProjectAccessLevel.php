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

    /**
     * The levels a project may hand to a workspace member who has no membership row of their
     * own. `Owner` is not among them: managing a project belongs to the people who were named
     * on it, never to everybody who can see it.
     *
     * @return list<self>
     */
    public static function grantableByDefault(): array
    {
        return [self::Editor, self::Commenter, self::Viewer];
    }

    /**
     * The levels that may change what a project holds, as values — the SQL half of
     * `canEdit()`, for the queries that decide this for many projects at once.
     *
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
