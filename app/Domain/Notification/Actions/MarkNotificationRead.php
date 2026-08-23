<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use Illuminate\Notifications\DatabaseNotification;

/**
 * Say that somebody has seen a line of their inbox.
 *
 * Idempotent, and deliberately not a toggle: reading something twice does not unread it, and the
 * original `read_at` is when they first saw it rather than when they last clicked. A second
 * request is answered by the state already being true.
 *
 * No authorization here: which notification this is has already been decided by the binding,
 * which resolves only inside the reader's own inbox (`routes/inbox.php`). An Action cannot
 * improve on "you can only name your own".
 */
final readonly class MarkNotificationRead
{
    public function handle(DatabaseNotification $notification): bool
    {
        if ($notification->read_at !== null) {
            return false;
        }

        $notification->forceFill(['read_at' => now()])->save();

        return true;
    }
}
