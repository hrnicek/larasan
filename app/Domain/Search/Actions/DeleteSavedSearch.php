<?php

declare(strict_types=1);

namespace App\Domain\Search\Actions;

use App\Domain\Search\Models\SavedSearch;

final readonly class DeleteSavedSearch
{
    public function handle(SavedSearch $search): void
    {
        $search->delete();
    }
}
