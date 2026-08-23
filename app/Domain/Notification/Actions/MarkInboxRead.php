<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Clear one inbox, in one request.
 *
 * Only the unread rows are touched, so "mark all read" cannot rewrite when somebody first saw
 * the things they had already seen — the same rule the single-notification Action follows, at
 * scale.
 *
 * Scoped to the workspace as well as the reader: somebody standing in one workspace clearing
 * another one's inbox would hide things they have never looked at.
 */
final readonly class MarkInboxRead
{
    public function handle(Workspace $workspace, User $reader): int
    {
        return DatabaseNotification::query()
            ->where('workspace_id', $workspace->id)
            ->where('notifiable_type', 'user')
            ->where('notifiable_id', $reader->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
