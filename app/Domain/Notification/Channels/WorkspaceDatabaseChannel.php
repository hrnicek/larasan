<?php

declare(strict_types=1);

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Contracts\WorkspaceNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * The framework's database channel, plus the column the framework's table does not have.
 *
 * Replacing the channel rather than writing the row by hand keeps everything else the framework
 * does — the notification's own id, the morph, `read_at`, the `Notifiable` relation — and adds
 * only what this application needs on top.
 *
 * A notification that is not a `WorkspaceNotification` is refused rather than written with a
 * null: the alternative is a row the Inbox cannot place and the badge cannot count, discovered
 * long after whoever added the notification has moved on.
 */
class WorkspaceDatabaseChannel extends DatabaseChannel
{
    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(mixed $notifiable, Notification $notification): array
    {
        if (! $notification instanceof WorkspaceNotification) {
            throw new LogicException(sprintf(
                'Database notification [%s] must implement %s.',
                $notification::class,
                WorkspaceNotification::class,
            ));
        }

        return [
            ...parent::buildPayload($notifiable, $notification),
            'workspace_id' => $notification->workspaceId(),
        ];
    }
}
