<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * The views of one person's work (`docs/ui/inbox.md`).
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

    /**
     * The one tab that is not about assignment. Everything else here answers "what am I
     * responsible for"; this answers "what did I want at hand", which is a different question and
     * may hold a task somebody else is doing.
     */
    case Starred = 'starred';

    public function showsCompleted(): bool
    {
        return $this === self::Completed;
    }

    /** Whether the tab lists what somebody was given, or what they marked themselves. */
    public function isAboutAssignment(): bool
    {
        return $this !== self::Starred;
    }
}
