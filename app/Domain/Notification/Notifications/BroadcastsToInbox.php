<?php

declare(strict_types=1);

namespace App\Domain\Notification\Notifications;

use App\Domain\Notification\Queries\InboxQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Notifications\Messages\BroadcastMessage;

trait BroadcastsToInbox
{
    /**
     * Queued on `notifications` rather than `broadcasts` so inbox bursts never delay board updates. See ADR-0008.
     */
    private function inboxBroadcast(mixed $notifiable, string $kind): BroadcastMessage
    {
        $workspace = Workspace::query()->find($this->workspaceId());

        // The framework overwrites `id` and `type` in the payload, so the notification kind is sent as `kind`.
        return (new BroadcastMessage([
            'kind' => $kind,
            'workspaceId' => $this->workspaceId(),
            'unread' => $workspace instanceof Workspace && $notifiable instanceof User
                ? app(InboxQuery::class)->unreadCount($workspace, $notifiable)
                : 0,
        ]))->onQueue('notifications');
    }

    public function broadcastType(): string
    {
        return 'notification.created';
    }
}
