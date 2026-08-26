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

    /**
     * A field the task's projects do not show is a value nobody would ever see: the screens
     * render a project's fields, so writing one anyway would be data with no way back out.
     */
    public static function fieldIsNotOnThisTask(): self
    {
        return new self('That field is not shown on this task.');
    }

    public static function optionIsNotOnThisField(): self
    {
        return new self('That choice does not belong to this field.');
    }

    /**
     * Only a `select` has choices to edit — every other type's answer lives in a column of its
     * own, and offering a choice list for one would be a control that decides nothing.
     */
    public static function fieldIsNotAChoiceField(): self
    {
        return new self('That field does not offer choices.');
    }

    public static function selectNeedsOptions(): self
    {
        return new self('A choice field needs at least one choice.');
    }
}
