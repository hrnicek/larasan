<?php

declare(strict_types=1);

namespace App\Domain\Page\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

final class PageException extends DomainException implements DomainRefusal
{
    public static function cannotWritePages(): self
    {
        return new self('You do not have permission to change the pages of this project.');
    }

    public static function notADocument(): self
    {
        return new self('That does not look like a page document.');
    }

    public static function documentIsTooDeep(): self
    {
        return new self('That document is nested more deeply than a page can hold.');
    }

    public static function documentIsTooLarge(): self
    {
        return new self('That document is larger than a page can hold.');
    }

    public static function changedElsewhere(): self
    {
        return new self('This page was changed somewhere else while you were writing.');
    }

    public static function parentBelongsToAnotherProject(): self
    {
        return new self('That page is not in this project.');
    }

    public static function anchorIsNotASibling(): self
    {
        return new self('That page is not where this one would be placed.');
    }

    public static function cannotContainItself(): self
    {
        return new self('A page cannot be moved inside itself.');
    }

    public static function nestedTooDeeply(): self
    {
        return new self('Pages cannot be nested any deeper than that.');
    }
}
