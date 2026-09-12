<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

interface DeduplicatesNotifications
{
    /**
     * Derived from the event alone, so a repeat of the same event collapses into the existing row.
     */
    public function deduplicationKey(): string;
}
