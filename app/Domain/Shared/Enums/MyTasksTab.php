<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum MyTasksTab: string
{
    case Today = 'today';
    case Upcoming = 'upcoming';
    case Overdue = 'overdue';
    case Completed = 'completed';
    case Starred = 'starred';

    public function showsCompleted(): bool
    {
        return $this === self::Completed;
    }

    public function isAboutAssignment(): bool
    {
        return $this !== self::Starred;
    }
}
