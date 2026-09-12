<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Listeners;

use App\Domain\Workspace\Actions\ClaimWorkspaceInvitations;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

/**
 * Not queued, because the next screen after registration lists the account's invitations.
 */
final readonly class ClaimInvitationsForNewAccount
{
    public function __construct(private ClaimWorkspaceInvitations $claim) {}

    public function handle(Registered $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->claim->handle($event->user);
    }
}
