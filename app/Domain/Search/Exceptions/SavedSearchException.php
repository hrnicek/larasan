<?php

declare(strict_types=1);

namespace App\Domain\Search\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

final class SavedSearchException extends DomainException implements DomainRefusal
{
    public static function nameIsTaken(string $name): self
    {
        return new self(__('You already have a saved search called “:name”.', ['name' => $name]));
    }

    public static function tooMany(int $limit): self
    {
        return new self(__('A workspace holds at most :limit saved searches per person.', ['limit' => $limit]));
    }

    public static function termIsEmpty(): self
    {
        return new self('A saved search needs something to search for.');
    }
}
