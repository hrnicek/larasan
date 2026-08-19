<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Exceptions;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use DomainException;

/**
 * Invariants an Action refuses regardless of who calls it. Transport layers translate
 * these into their own vocabulary — a FormRequest rejects most of them before the Action
 * is reached, and the Action still checks, because a console command or a queued job
 * arrives without one.
 */
final class WorkspaceMembershipException extends DomainException
{
    public static function alreadyAMember(): self
    {
        return new self('That user is already a member of this workspace.');
    }

    public static function cannotInviteAsOwner(): self
    {
        return new self('A workspace has one owner, set when it is created. Transferring ownership is a separate operation.');
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
}
