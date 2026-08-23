<?php

declare(strict_types=1);

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Contracts\DeduplicatesNotifications;
use App\Domain\Notification\Contracts\WorkspaceNotification;
use Illuminate\Database\Eloquent\Model;
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
            'dedupe_key' => $notification instanceof DeduplicatesNotifications
                ? $notification->deduplicationKey()
                : null,
        ];
    }

    /**
     * Write the row, unless this person already has it.
     *
     * The queued listeners that send these are retried, and a job that failed after its insert
     * used to leave a second line in an Inbox for the same comment. The unique index is what
     * makes that impossible; this is what turns the collision into silence rather than a job
     * that fails forever on its own success (TASK-180-021).
     */
    public function send(mixed $notifiable, Notification $notification): Model
    {
        if (! $notification instanceof DeduplicatesNotifications) {
            return parent::send($notifiable, $notification);
        }

        $payload = $this->buildPayload($notifiable, $notification);

        $existing = $notifiable->routeNotificationFor('database', $notification)
            ->where('dedupe_key', $payload['dedupe_key'])
            ->first();

        return $existing instanceof Model
            ? $existing
            : parent::send($notifiable, $notification);
    }
}
