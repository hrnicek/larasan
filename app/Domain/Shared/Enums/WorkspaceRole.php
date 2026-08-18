<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Source of workspace capabilities (ADR-0010). Policies ask this enum rather than
 * comparing role strings, and the check is a pure function of the membership row so it
 * behaves identically in HTTP requests, queued jobs, console commands and broadcast
 * authorization.
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Guest = 'guest';

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Owner => Capability::cases(),
            self::Admin => array_values(array_filter(
                Capability::cases(),
                fn (Capability $capability): bool => $capability !== Capability::WorkspaceDelete,
            )),
            self::Member => [
                Capability::ProjectCreate,
                Capability::ProjectUpdate,
                Capability::ProjectDelete,
                Capability::TaskCreate,
                Capability::TaskUpdate,
                Capability::TaskDelete,
                Capability::TaskAssign,
                Capability::CommentCreate,
                Capability::CommentDelete,
                Capability::FileUpload,
            ],
            self::Guest => [
                Capability::CommentCreate,
            ],
        };
    }

    public function allows(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), strict: true);
    }

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }
}
