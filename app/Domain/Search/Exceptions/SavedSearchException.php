<?php

declare(strict_types=1);

namespace App\Domain\Search\Exceptions;

use DomainException;

final class SavedSearchException extends DomainException
{
    public static function nameIsTaken(string $name): self
    {
        return new self("You already have a saved search called “{$name}”.");
    }

    public static function tooMany(int $limit): self
    {
        return new self("A workspace holds at most {$limit} saved searches per person.");
    }

    public static function termIsEmpty(): self
    {
        return new self('A saved search needs something to search for.');
    }
}
