<?php

declare(strict_types=1);

namespace App\Domain\Project\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

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

    public static function cannotStarUnreachableProject(): self
    {
        return new self('You do not have access to that project.');
    }

    public static function projectNeedsAnOwner(): self
    {
        return new self('A project needs at least one owner.');
    }

    public static function membershipIsNotOnThisProject(): self
    {
        return new self('That membership does not belong to this project.');
    }

    public static function memberIsNotInTheWorkspace(): self
    {
        return new self('That person is not an active member of this project\'s workspace.');
    }
}
