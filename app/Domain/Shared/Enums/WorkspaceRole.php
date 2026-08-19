<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Source of workspace capabilities (ADR-0010). Policies ask this enum rather than
 * comparing role strings, and the check is a pure function of the membership row so it
 * behaves identically in HTTP requests, queued jobs, console commands and broadcast
 * authorization.
 *
 * This answers only "what may this role do"; it does not know whether the membership is
 * active. Callers must compose it with WorkspaceMembershipStatus::grantsAccess().
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Guest = 'guest';

    /**
     * Listed case by case rather than derived, so adding a Capability forces an
     * explicit decision for every role instead of silently granting it to the
     * broadest ones.
     *
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Owner => [
                Capability::WorkspaceManage,
                Capability::WorkspaceDelete,
                Capability::WorkspaceMembersManage,
                Capability::ProjectCreate,
                Capability::ProjectUpdate,
                Capability::ProjectDelete,
                Capability::SectionCreate,
                Capability::SectionUpdate,
                Capability::SectionDelete,
                Capability::TaskCreate,
                Capability::TaskUpdate,
                Capability::TaskDelete,
                Capability::TaskAssign,
                Capability::CommentCreate,
                Capability::CommentDelete,
                Capability::TagManage,
                Capability::CustomFieldManage,
                Capability::FileUpload,
                Capability::FileDelete,
            ],
            self::Admin => [
                Capability::WorkspaceManage,
                Capability::WorkspaceMembersManage,
                Capability::ProjectCreate,
                Capability::ProjectUpdate,
                Capability::ProjectDelete,
                Capability::SectionCreate,
                Capability::SectionUpdate,
                Capability::SectionDelete,
                Capability::TaskCreate,
                Capability::TaskUpdate,
                Capability::TaskDelete,
                Capability::TaskAssign,
                Capability::CommentCreate,
                Capability::CommentDelete,
                Capability::TagManage,
                Capability::CustomFieldManage,
                Capability::FileUpload,
                Capability::FileDelete,
            ],
            self::Member => [
                Capability::ProjectCreate,
                Capability::ProjectUpdate,
                Capability::ProjectDelete,
                Capability::SectionCreate,
                Capability::SectionUpdate,
                Capability::SectionDelete,
                Capability::TaskCreate,
                Capability::TaskUpdate,
                Capability::TaskDelete,
                Capability::TaskAssign,
                Capability::CommentCreate,
                Capability::CommentDelete,
                Capability::TagManage,
                Capability::FileUpload,
                Capability::FileDelete,
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

    /**
     * Guests are outside collaborators: they reach only what they were explicitly given,
     * which is why project visibility never grants them anything (ADR-0006 with ADR-0010).
     */
    public function isGuest(): bool
    {
        return $this === self::Guest;
    }
}
