<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * The four views of one person's work (`docs/ui/inbox.md`).
 *
 * Values appear in URLs — a link to My Tasks carries the tab it was read in — so they are
 * stable.
 */
enum MyTasksTab: string
{
    case Today = 'today';
    case Upcoming = 'upcoming';
    case Overdue = 'overdue';
    case Completed = 'completed';

    public function showsCompleted(): bool
    {
        return $this === self::Completed;
    }
}
