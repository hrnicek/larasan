<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\Builder as SearchBuilder;
use Laravel\Scout\Engines\MeilisearchEngine;

/**
 * The user index has no workspace attribute, so tenant isolation relies on the membership constraint. See ADR-0016.
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
            return [];
        }

        $people = PersonSummary::for($workspace, $actor);
        $byNameOnly = ! $people->revealsAddresses;

        // An address is indexed, so a reader who may not see addresses must not confirm one by matching it.
        $results = User::search($term)
            ->when($byNameOnly, fn (SearchBuilder $search): SearchBuilder => $search->options(['attributesToSearchOn' => ['name']]))
            ->query(fn (Builder $users): Builder => $users
                ->whereHas('workspaceMemberships', fn (Builder $memberships): Builder => $memberships
                    ->where('workspace_id', $workspace->id)
                    ->where('status', WorkspaceMembershipStatus::Active->value))
                ->when($byNameOnly && ! $this->engineHonoursSearchAttributes(), fn (Builder $users): Builder => $users
                    ->whereLike('users.name', '%'.addcslashes($term, '%_\\').'%'))
                ->with(['workspaceMemberships' => fn ($memberships) => $memberships
                    ->where('workspace_id', $workspace->id)])
                ->select(PersonSummary::columns()))
            ->take($limit * self::CANDIDATES_PER_RESULT)
            ->get()
            ->take($limit)
            ->map(fn (User $person): array => [
                ...$people->of($person),
                'role' => $person->workspaceMemberships->first()?->role->value,
            ]);

        return array_values($results->all());
    }

    /**
     * Only Meilisearch reads `attributesToSearchOn`; the collection and database engines match every indexed field.
     */
    private function engineHonoursSearchAttributes(): bool
    {
        return (new User)->searchableUsing() instanceof MeilisearchEngine;
    }
}
