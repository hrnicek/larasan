<?php

declare(strict_types=1);

namespace App\Domain\Shared\Ordering;

use DomainException;

/**
 * Not user-facing: the caller normalises the set and retries the move. See ADR-0009.
 */
final class PositionsNeedNormalisation extends DomainException
{
    public function __construct()
    {
        parent::__construct('There is no room left between these positions; normalise the set first.');
    }
}
