<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

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
