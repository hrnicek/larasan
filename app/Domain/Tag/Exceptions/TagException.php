<?php

declare(strict_types=1);

namespace App\Domain\Tag\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

final class TagException extends DomainException implements DomainRefusal
{
    public static function cannotTagTask(): self
    {
        return new self('You do not have permission to change this task.');
    }

    /**
     * Two valid ids that must not be combined. The foreign keys prove each row exists; only this
     * proves they belong to the same tenant.
     */
    public static function tagIsFromAnotherWorkspace(): self
    {
        return new self('That tag is not in this workspace.');
    }

    public static function cannotManageTags(): self
    {
        return new self('You do not have permission to manage tags in this workspace.');
    }

    public static function nameIsTaken(): self
    {
        return new self('A tag with that name already exists.');
    }

    public static function nameIsEmpty(): self
    {
        return new self('A tag needs a name.');
    }
}
