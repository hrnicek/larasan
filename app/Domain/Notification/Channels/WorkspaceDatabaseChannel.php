<?php

declare(strict_types=1);

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Contracts\DeduplicatesNotifications;
use App\Domain\Notification\Contracts\WorkspaceNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use LogicException;

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
     * Retried queue jobs return the existing row instead of violating the `dedupe_key` unique index.
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
