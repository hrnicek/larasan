<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Actions;

use App\Domain\Shared\Access\MembershipRegistry;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Claiming only attaches the account; the invitation stays Invited with its deadline.
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

            $this->registry->flush();

            return $claimed->values();
        });
    }

    private function claim(WorkspaceMembership $membership, User $user): bool
    {
        // UNIQUE(workspace_id, user_id) forbids a second row, so the existing membership wins.
        if ($membership->workspace->membershipFor($user) instanceof WorkspaceMembership) {
            $membership->delete();

            return false;
        }

        $membership->forceFill(['user_id' => $user->id])->save();

        return true;
    }
}
