<?php

declare(strict_types=1);

namespace App\Domain\Search\Actions;

use App\Domain\Search\Data\SaveSearchData;
use App\Domain\Search\Exceptions\SavedSearchException;
use App\Domain\Search\Models\SavedSearch;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * Keep a search.
 *
 * The cap exists because the chips are drawn in one row a person reads at a glance; past a
 * couple of dozen it is a list, and a list of searches is a screen nobody asked for.
 */
final readonly class SaveSearch
{
    public const LIMIT = 24;

    public function handle(Workspace $workspace, User $owner, SaveSearchData $data): SavedSearch
    {
        if ($data->term === '') {
            throw SavedSearchException::termIsEmpty();
        }

        $held = SavedSearch::query()
            ->where('user_id', $owner->id)
            ->where('workspace_id', $workspace->id);

        if ((clone $held)->where('name', $data->name)->exists()) {
            // The unique index is what makes this true under concurrency; this is what makes
            // the answer a sentence rather than a constraint violation.
            throw SavedSearchException::nameIsTaken($data->name);
        }

        if ((clone $held)->count() >= self::LIMIT) {
            throw SavedSearchException::tooMany(self::LIMIT);
        }

        $search = new SavedSearch([
            'name' => $data->name,
            'term' => $data->term,
            'kind' => $data->kind,
            'filters' => $data->filters,
        ]);

        $search->user_id = $owner->id;
        $search->workspace_id = $workspace->id;
        $search->save();

        return $search;
    }
}
