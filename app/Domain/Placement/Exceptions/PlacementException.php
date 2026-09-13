<?php

declare(strict_types=1);

namespace App\Domain\Placement\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

final class PlacementException extends DomainException implements DomainRefusal
{
    public static function cannotPlaceTasks(): self
    {
        return new self('You do not have permission to change what this project holds.');
    }

    public static function sectionBelongsToAnotherProject(): self
    {
        return new self('That section is not in this project.');
    }

    public static function cardIsNotInThatColumn(): self
    {
        return new self('That card is not in this column.');
    }

    public static function cannotFollowItself(): self
    {
        return new self('A task cannot be placed after itself.');
    }

    public static function taskBelongsToAnotherWorkspace(): self
    {
        return new self('That task is not in this workspace.');
    }

    public static function cannotLeaveItsOnlyPrivateProject(): self
    {
        return new self('This task is only in this private project. Add it to another project first, or delete it.');
    }
}
