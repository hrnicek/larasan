<?php

declare(strict_types=1);

namespace App\Domain\Page\Policies;

use App\Domain\Page\Models\Page;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

/**
 * Page creation is authorized by `ProjectPolicy::createPage()`.
 */
class PagePolicy
{
    public function view(User $user, Page $page): bool
    {
        return $page->project->isVisibleTo($user);
    }

    public function update(User $user, Page $page): bool
    {
        return $page->project->allowsChangesBy($user, Capability::PageUpdate);
    }

    public function delete(User $user, Page $page): bool
    {
        return $page->project->allowsChangesBy($user, Capability::PageDelete);
    }
}
