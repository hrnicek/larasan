<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Exceptions;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

final class WorkspaceMembershipException extends DomainException implements DomainRefusal
{
    public static function alreadyAMember(): self
    {
        return new self('That user is already a member of this workspace.');
    }

    public static function alreadyInvited(): self
    {
        return new self('That person already has an invitation waiting.');
    }

    public static function cannotAssignOwner(): self
    {
        return new self('Ownership is transferred, not assigned. A workspace has one owner, set when it is created.');
    }

    public static function roleRequiresCapability(WorkspaceRole $role): self
    {
        return new self(__('The actor may not grant the :role role in this workspace.', ['role' => $role->noun()]));
    }

    public static function notTheInvitee(): self
    {
        return new self('An invitation can only be answered by the person it was sent to.');
    }

    /** One sentence per status, because the translated adjective has to agree with "invitation". */
    public static function invitationNotPending(WorkspaceMembershipStatus $status): self
    {
        return new self(match ($status) {
            WorkspaceMembershipStatus::Invited => 'This invitation is invited and can no longer be answered.',
            WorkspaceMembershipStatus::Active => 'This invitation is active and can no longer be answered.',
            WorkspaceMembershipStatus::Declined => 'This invitation is declined and can no longer be answered.',
            WorkspaceMembershipStatus::Revoked => 'This invitation is revoked and can no longer be answered.',
            WorkspaceMembershipStatus::Expired => 'This invitation is expired and can no longer be answered.',
        });
    }

    public static function invitationExpired(): self
    {
        return new self('This invitation has expired. Ask for a new one.');
    }

    public static function inviterNoLongerMayInvite(): self
    {
        return new self('The person who sent this invitation can no longer invite members. Ask someone else for a new one.');
    }

    public static function lastOwner(): self
    {
        return new self('A workspace keeps at least one owner. Make someone else an owner first.');
    }

    public static function onlyAnOwnerActsOnAnOwner(): self
    {
        return new self('Only an owner can change or remove another owner.');
    }

    public static function cannotChangeOwnRole(): self
    {
        return new self('A member cannot change their own role.');
    }
}
