<?php

declare(strict_types=1);

namespace App\Domain\Section\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

final class SectionException extends DomainException implements DomainRefusal
{
    public static function cannotManageSections(): self
    {
        return new self('You do not have permission to change the sections of this project.');
    }

    public static function sectionBelongsToAnotherProject(): self
    {
        return new self('That section is not in this project.');
    }

    public static function cannotFollowItself(): self
    {
        return new self('A section cannot be placed after itself.');
    }
}
