<?php

declare(strict_types=1);

namespace App\Domain\Search\Policies;

use App\Domain\Search\Models\SavedSearch;
use App\Models\User;

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
