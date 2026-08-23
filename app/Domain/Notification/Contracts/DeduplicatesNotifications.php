<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

/**
 * A notification that can say when it is the same notification as one already sent.
 *
 * The listeners that send these are queued, and a queued job that fails after its insert is
 * retried — so "the same" has to be answerable from the event alone, not from the instance that
 * happened to be built this time (TASK-180-021).
 *
 * A notification that has nothing to be deduplicated by simply does not implement this.
 */
interface DeduplicatesNotifications
{
    /**
     * A stable key for this notification, derived from what happened rather than from when.
     *
     * The consequence is worth knowing: two identical happenings produce one line. A comment is
     * created once, so its key is exact; an assignment repeated after an unassignment is the
     * same sentence twice, and one of them is enough.
     */
    public function deduplicationKey(): string;
}
