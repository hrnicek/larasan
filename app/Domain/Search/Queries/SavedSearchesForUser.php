<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Search\Models\SavedSearch;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * The chips under the palette's field: this person's saved searches in this workspace, oldest
 * first so a row somebody has learned the shape of does not reshuffle when they keep another.
 */
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
            // `created_at` is `timestamp(0)` in this database and two chips kept in the same
            // second would otherwise come back in whichever order PostgreSQL chose that day.
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
