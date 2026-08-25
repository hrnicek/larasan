<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The people a term finds, for the palette.
 *
 * The index holds every user in the installation — a person belongs to several workspaces, so
 * there is no tenant column to filter on — which is exactly why the boundary is the join:
 * `workspace_memberships` for *this* workspace, and only memberships that grant access. A
 * member of another workspace is never returned (ADR-0016).
 *
 * The actor is not excluded. "Assign to me" is a thing people search for, and a list that
 * silently lacks the person doing the searching reads as a bug.
 */
final readonly class PersonResults
{
    /** @see TaskResults::CANDIDATES_PER_RESULT */
    private const CANDIDATES_PER_RESULT = 4;

    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(Workspace $workspace, User $actor, string $term, int $limit = 5): array
    {
        $term = trim($term);

        if ($term === '' || $limit < 1) {
            return [];
        }

        if ($workspace->membershipFor($actor)?->status->grantsAccess() !== true) {
            // Not in this workspace at all: its people are not this actor's to find.
            return [];
        }

        $results = User::search($term)
            ->query(fn (Builder $users): Builder => $users
                ->whereHas('workspaceMemberships', fn (Builder $memberships): Builder => $memberships
                    ->where('workspace_id', $workspace->id)
                    ->where('status', WorkspaceMembershipStatus::Active->value))
                ->with(['workspaceMemberships' => fn ($memberships) => $memberships
                    ->where('workspace_id', $workspace->id)])
                ->select(['id', 'name', 'email']))
            ->take($limit * self::CANDIDATES_PER_RESULT)
            ->get()
            ->take($limit)
            ->map(fn (User $person): array => [
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
                'role' => $person->workspaceMemberships->first()?->role->value,
            ]);

        return array_values($results->all());
    }
}
