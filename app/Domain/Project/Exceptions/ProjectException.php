<?php

declare(strict_types=1);

namespace App\Domain\Project\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

/**
 * Invariants the project Actions refuse for every caller. Transport layers translate
 * these; a FormRequest catches most of them first, and the Action still checks, because
 * a console command or a queued job arrives without one.
 */
final class ProjectException extends DomainException implements DomainRefusal
{
    public static function cannotManageProject(): self
    {
        return new self('You do not have permission to change this project.');
    }

    public static function cannotCreateProjects(): self
    {
        return new self('You do not have permission to create projects in this workspace.');
    }

    /**
     * Starring is a shortcut to a project, so it cannot point at one the actor may not open —
     * the rule `FollowTask` applies to notifications, applied here to navigation.
     */
    public static function cannotStarUnreachableProject(): self
    {
        return new self('You do not have access to that project.');
    }

    /**
     * Managing a project needs an explicit `owner` row (`Project::isManageableBy`), so a project
     * whose last owner was demoted or removed is one nobody can manage — not an editor, not the
     * workspace's own owner. The row is the only way back in, so it cannot be the row that goes.
     */
    public static function projectNeedsAnOwner(): self
    {
        return new self('A project needs at least one owner.');
    }

    public static function membershipIsNotOnThisProject(): self
    {
        return new self('That membership does not belong to this project.');
    }

    /**
     * The invariant TASK-040-021 recorded in Phase 040: access to a project is access inside
     * a workspace, so a project membership for somebody who is not in that workspace is a
     * grant that means nothing and reads as if it means something.
     */
    public static function memberIsNotInTheWorkspace(): self
    {
        return new self('That person is not an active member of this project\'s workspace.');
    }
}
