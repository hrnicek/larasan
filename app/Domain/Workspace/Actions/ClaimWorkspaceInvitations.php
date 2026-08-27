<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Actions;

use App\Domain\Shared\Access\MembershipRegistry;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An address that has been invited and then registers takes over what was waiting for it.
 *
 * Without this an invitation sent before the account existed would stay addressed to nobody:
 * every authorization path filters on `user_id`, so the row grants nothing and the invitee
 * has nothing to answer.
 *
 * Claiming is not accepting. The row stays `invited` and its deadline stands — registering
 * says who you are, not that you agree to join.
 */
final readonly class ClaimWorkspaceInvitations
{
    public function __construct(private MembershipRegistry $registry) {}

    /**
     * @return Collection<int, WorkspaceMembership> the rows this account now holds
     */
    public function handle(User $user): Collection
    {
        $waiting = WorkspaceMembership::query()
            ->with('workspace')
            ->whereNull('user_id')
            ->where('email', mb_strtolower($user->email))
            ->get();

        if ($waiting->isEmpty()) {
            return $waiting;
        }

        return DB::transaction(function () use ($waiting, $user): Collection {
            $claimed = $waiting->filter(fn (WorkspaceMembership $membership): bool => $this->claim($membership, $user));

            // The rows this actor holds have changed, and the memo is keyed by actor.
            $this->registry->flush();

            return $claimed->values();
        });
    }

    private function claim(WorkspaceMembership $membership, User $user): bool
    {
        /*
         * An account that already has a row in that workspace — because it was invited under
         * one address and registered under another, then changed to this one — cannot take a
         * second: `UNIQUE(workspace_id, user_id)` refuses it, and the row they already have
         * is the authoritative one. The invitation is dropped rather than merged.
         */
        if ($membership->workspace->membershipFor($user) instanceof WorkspaceMembership) {
            $membership->delete();

            return false;
        }

        $membership->forceFill(['user_id' => $user->id])->save();

        return true;
    }
}
