<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Listeners;

use App\Domain\Workspace\Actions\ClaimWorkspaceInvitations;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

/**
 * Somebody invited before they had an account finds the invitation waiting the moment they
 * have one.
 *
 * Not queued: the account is registered and the next screen already asks what this account
 * has been invited to. A worker deciding that a second later is a screen that says *nothing
 * here* to somebody who followed an invitation link to get to it.
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
