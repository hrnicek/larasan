<?php

declare(strict_types=1);

namespace App\Domain\Task\Exceptions;

use App\Domain\Shared\Exceptions\DomainRefusal;
use DomainException;

final class DescriptionConflict extends DomainException implements DomainRefusal
{
    public function __construct()
    {
        parent::__construct('This description changed elsewhere. Copy your text before reloading the task.');
    }
}
