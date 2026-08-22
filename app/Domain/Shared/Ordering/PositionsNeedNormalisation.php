<?php

declare(strict_types=1);

namespace App\Domain\Shared\Ordering;

use DomainException;

/**
 * Not a user-facing failure: the caller catches this, normalises the set and retries the
 * move. It exists so that running out of room is impossible to mistake for a valid
 * position (ADR-0009).
 */
final class PositionsNeedNormalisation extends DomainException
{
    public function __construct()
    {
        parent::__construct('There is no room left between these positions; normalise the set first.');
    }
}
