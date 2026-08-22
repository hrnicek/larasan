<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

/**
 * A notification that knows which workspace it came from.
 *
 * Every database notification here is one. The Inbox is workspace-scoped and the shell's badge
 * is a per-workspace unread count, so a row without a workspace would have to be placed by
 * loading whatever it points at — per row, on every page.
 */
interface WorkspaceNotification
{
    public function workspaceId(): string;
}
