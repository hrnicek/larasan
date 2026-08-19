<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Models\WorkspaceMembership;
use Illuminate\Console\Command;

class ExpireWorkspaceInvitations extends Command
{
    protected $signature = 'workspaces:expire-invitations';

    protected $description = 'Mark workspace invitations whose deadline has passed as expired';

    /**
     * Without this, `WorkspaceMembershipStatus::Expired` is a case nothing ever writes.
     * Acceptance already refuses a lapsed invitation by reading `expires_at`, so this is
     * about the member list telling the truth rather than about access control — which is
     * why it can run on a schedule rather than on the request path.
     */
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

        $this->components->info("Expired {$expired} ".str('invitation')->plural($expired).'.');

        return self::SUCCESS;
    }
}
