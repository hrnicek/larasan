<?php

declare(strict_types=1);

namespace App\Domain\Search\Actions;

use App\Domain\Search\Models\SavedSearch;

/**
 * Forget a search. No soft delete: a bookmark somebody removed is not history, and a trash can
 * for search chips is a screen this application will never draw.
 */
final readonly class DeleteSavedSearch
{
    public function handle(SavedSearch $search): void
    {
        $search->delete();
    }
}
