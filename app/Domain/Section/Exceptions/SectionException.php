<?php

declare(strict_types=1);

namespace App\Domain\Section\Exceptions;

use DomainException;

/**
 * Invariants the section Actions refuse for every caller. A FormRequest catches most of
 * them first; the Action still checks, because a console command or a queued job arrives
 * without one.
 */
final class SectionException extends DomainException
{
    public static function cannotManageSections(): self
    {
        return new self('You do not have permission to change the sections of this project.');
    }
}
