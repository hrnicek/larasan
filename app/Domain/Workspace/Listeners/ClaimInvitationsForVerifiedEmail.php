<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Listeners;

use App\Domain\Workspace\Actions\ClaimWorkspaceInvitations;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

/**
 * Not queued, because the next screen after verification lists the account's invitations.
 */
final readonly class ClaimInvitationsForVerifiedEmail
{
    public function __construct(private ClaimWorkspaceInvitations $claim) {}

    public function handle(Verified $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->claim->handle($event->user);
    }
}
