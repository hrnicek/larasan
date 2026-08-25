<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * List, Board and Calendar render the same domain data (ADR-0003) — grouped by column, by
 * column again, and by due date; this only decides which one opens when no view is named in
 * the URL.
 */
enum ProjectDefaultView: string
{
    case List = 'list';
    case Board = 'board';
    case Calendar = 'calendar';
}
