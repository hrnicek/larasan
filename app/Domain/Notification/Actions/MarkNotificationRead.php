<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use Illuminate\Notifications\DatabaseNotification;

/**
 * Authorized by the route binding in `routes/inbox.php`, which only resolves the reader's own notifications.
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
