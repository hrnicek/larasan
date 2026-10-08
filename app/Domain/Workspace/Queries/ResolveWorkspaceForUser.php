<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Queries;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scoped to active memberships, so an inaccessible workspace resolves to null. See ADR-0005.
 */
final readonly class ResolveWorkspaceForUser
{
    public function __invoke(User $user, ?string $slug = null): ?Workspace
    {
        if ($slug !== null) {
            return $this->membershipsOf($user)->where('slug', $slug)->first();
        }

        $remembered = $user->current_workspace_id === null
            ? null
            : $this->membershipsOf($user)->whereKey($user->current_workspace_id)->first();

        // created_at is timestamp(0), so the UUIDv7 key breaks same-second ties in creation order.
        return $remembered
            ?? $this->membershipsOf($user)->oldest('created_at')->orderBy('id')->first();
    }

    /**
     * A fresh builder per call, so constraints never leak from one lookup into the next.
     *
     * @return Builder<Workspace>
     */
    private function membershipsOf(User $user): Builder
    {
        return Workspace::query()
            ->whereHas('memberships', fn (Builder $memberships): Builder => $memberships
                ->where('user_id', $user->id)
                ->where('status', WorkspaceMembershipStatus::Active->value));
    }
}
