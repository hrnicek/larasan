<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

final class CustomFieldException extends DomainException implements DomainRefusal
{
    public static function cannotManageFields(): self
    {
        return new self('You do not have permission to manage custom fields in this workspace.');
    }

    public static function cannotSetValue(): self
    {
        return new self('You do not have permission to change this task.');
    }

    public static function nameIsEmpty(): self
    {
        return new self('A field needs a name.');
    }

    public static function nameIsTaken(): self
    {
        return new self('A field with that name already exists.');
    }

    public static function fieldIsFromAnotherWorkspace(): self
    {
        return new self('That field is not in this workspace.');
    }

    public static function fieldIsNotOnThisTask(): self
    {
        return new self('That field is not shown on this task.');
    }

    public static function optionIsNotOnThisField(): self
    {
        return new self('That choice does not belong to this field.');
    }

    public static function fieldIsNotAChoiceField(): self
    {
        return new self('That field does not offer choices.');
    }

    public static function selectNeedsOptions(): self
    {
        return new self('A choice field needs at least one choice.');
    }
}
