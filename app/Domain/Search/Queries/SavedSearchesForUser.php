<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Search\Models\SavedSearch;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

final readonly class SavedSearchesForUser
{
    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(Workspace $workspace, User $owner): array
    {
        $searches = SavedSearch::query()
            ->where('user_id', $owner->id)
            ->where('workspace_id', $workspace->id)
            ->oldest('created_at')
            // `created_at` is `timestamp(0)`, so `id` breaks ties.
            ->orderBy('id')
            ->get()
            ->map(fn (SavedSearch $search): array => [
                'id' => $search->id,
                'name' => $search->name,
                'term' => $search->term,
                'kind' => $search->kind?->value,
                'filters' => (object) $search->filters,
            ]);

        return array_values($searches->all());
    }
}
