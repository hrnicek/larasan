<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * List and Board render the same domain data (ADR-0003); this only decides which one
 * opens when no view is named in the URL.
 */
enum ProjectDefaultView: string
{
    case List = 'list';
    case Board = 'board';
}
