<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\Access\MembershipRegistry;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Models\WorkspaceMembership;
use Illuminate\Console\Command;

class ExpireWorkspaceInvitations extends Command
{
    protected $signature = 'workspaces:expire-invitations';

    protected $description = 'Mark workspace invitations whose deadline has passed as expired';

    public function handle(): int
    {
        $expired = WorkspaceMembership::query()
            ->where('status', WorkspaceMembershipStatus::Invited->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update([
                'status' => WorkspaceMembershipStatus::Expired->value,
                'expires_at' => null,
            ]);

        // A mass update fires no model events, so the registry cannot notice it.
        app(MembershipRegistry::class)->flush();

        $this->components->info("Expired {$expired} ".str('invitation')->plural($expired).'.');

        return self::SUCCESS;
    }
}
