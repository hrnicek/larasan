<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Queries;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which workspace a request is operating in. The membership check is part of the query
 * rather than a policy the caller must remember (ADR-0005): an id the actor has no
 * active membership for resolves to null, and the middleware turns that into a 404.
 */
final readonly class ResolveWorkspaceForUser
{
    public function __invoke(User $user, ?string $slug = null): ?Workspace
    {
        if ($slug !== null) {
            return $this->membershipsOf($user)->where('slug', $slug)->first();
        }

        return $this->membershipsOf($user)->whereKey($user->current_workspace_id)->first()
            ?? $this->membershipsOf($user)->oldest('created_at')->first();
    }

    /**
     * A fresh builder per call. Reusing one would carry the remembered workspace's id
     * into the fallback, and a user who had left that workspace would resolve to nothing
     * while still being a member of others.
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
