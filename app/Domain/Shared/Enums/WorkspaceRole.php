<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Ignores membership status; callers must also check WorkspaceMembershipStatus::grantsAccess().
 * See ADR-0010.
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
                Capability::PageCreate,
                Capability::PageUpdate,
                Capability::PageDelete,
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
                Capability::PageCreate,
                Capability::PageUpdate,
                Capability::PageDelete,
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
                Capability::PageCreate,
                Capability::PageUpdate,
                Capability::PageDelete,
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

    /** Project visibility never grants a guest access; only explicit membership does. */
    public function isGuest(): bool
    {
        return $this === self::Guest;
    }
}
