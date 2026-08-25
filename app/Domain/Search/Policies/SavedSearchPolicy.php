<?php

declare(strict_types=1);

namespace App\Domain\Search\Policies;

use App\Domain\Search\Models\SavedSearch;
use App\Models\User;

/**
 * A saved search is a bookmark, so its rules are short: it belongs to the person who kept it and
 * to nobody else. Not shared, not inherited by a workspace's owner — a search whose filters name
 * projects half the workspace cannot open would otherwise become a list of things they are told
 * about and may not read.
 *
 * Resolved by auto-discovery, and asserted by `SavedSearchTest` — the
 * `app/Domain/<Context>/Policies` pairing is not the layout the framework documents, so a
 * namespace move would stop authorizing in silence.
 */
class SavedSearchPolicy
{
    public function view(User $user, SavedSearch $search): bool
    {
        return $search->user_id === $user->id;
    }

    public function delete(User $user, SavedSearch $search): bool
    {
        return $search->user_id === $user->id;
    }
}
