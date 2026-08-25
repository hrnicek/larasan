<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * The kinds of thing a search can find.
 *
 * A kind is a filter the palette's chips send and the search screen's tabs keep in the URL, so
 * the values are stable. Absent means all four, ranked within their own kind — nothing here
 * pretends a task and a person can be ranked against each other.
 */
enum SearchKind: string
{
    case Tasks = 'tasks';
    case Projects = 'projects';
    case People = 'people';
    case Messages = 'messages';
}
