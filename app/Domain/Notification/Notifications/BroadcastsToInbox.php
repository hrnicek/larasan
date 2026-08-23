<?php

declare(strict_types=1);

namespace App\Domain\Notification\Notifications;

use App\Domain\Notification\Queries\InboxQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * The Inbox and its badge, told rather than asked.
 *
 * The audience of `private-user.{user}` is one person — the person the notification belongs to —
 * so unlike the shared channels this payload may carry something beyond an id: the unread count
 * the shell's badge draws, which is otherwise a request the client has to make to learn that it
 * has one more thing to read.
 *
 * The row itself is still not here. It is rendered from ids resolved at read time
 * (`InboxQuery`), including whether the reader can still reach what it points at, and a payload
 * that shaped its own row would be a second implementation of that rule waiting to disagree
 * with the first.
 */
trait BroadcastsToInbox
{
    /**
     * The `notifications` queue rather than `broadcasts` (ADR-0008): a burst of mentions must
     * never be what delayed a board update.
     */
    private function inboxBroadcast(mixed $notifiable, string $kind): BroadcastMessage
    {
        $workspace = Workspace::query()->find($this->workspaceId());

        /*
         * `id` and `type` are the framework's own keys and are merged in afterwards — `type` is
         * always `notification.created` here, so which kind of notification this is has to be
         * called something else or it is silently overwritten.
         */
        return (new BroadcastMessage([
            'kind' => $kind,
            'workspaceId' => $this->workspaceId(),
            'unread' => $workspace instanceof Workspace && $notifiable instanceof User
                ? app(InboxQuery::class)->unreadCount($workspace, $notifiable)
                : 0,
        ]))->onQueue('notifications');
    }

    /**
     * One event name for every notification. Which kind it is belongs in the payload, where the
     * client reads it, rather than in an event name every screen has to know the list of.
     */
    public function broadcastType(): string
    {
        return 'notification.created';
    }
}
