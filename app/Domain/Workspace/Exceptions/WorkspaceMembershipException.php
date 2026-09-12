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
        return new self("The actor may not grant the {$role->value} role in this workspace.");
    }

    public static function notTheInvitee(): self
    {
        return new self('An invitation can only be answered by the person it was sent to.');
    }

    public static function invitationNotPending(WorkspaceMembershipStatus $status): self
    {
        return new self("This invitation is {$status->value} and can no longer be answered.");
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
