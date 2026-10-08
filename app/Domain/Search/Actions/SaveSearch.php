<?php

declare(strict_types=1);

namespace App\Domain\Search\Actions;

use App\Domain\Search\Data\SaveSearchData;
use App\Domain\Search\Exceptions\SavedSearchException;
use App\Domain\Search\Models\SavedSearch;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

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
            // The unique index enforces this under concurrency; the check only gives a readable refusal.
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
