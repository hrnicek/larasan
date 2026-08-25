<?php

declare(strict_types=1);

namespace App\Domain\Page\Policies;

use App\Domain\Page\Models\Page;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

/**
 * A page has no access rules of its own: it is written inside a project, and who may read or
 * change what is in that project is the project's answer (`SectionPolicy` asks the same two
 * questions for the same reason).
 *
 * Creation is not here — there is no page yet to judge. `ProjectPolicy::createPage()` answers
 * that one, on the thing that does exist.
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
