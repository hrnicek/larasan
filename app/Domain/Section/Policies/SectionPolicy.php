<?php

declare(strict_types=1);

namespace App\Domain\Section\Policies;

use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

/**
 * Sections have no authorization of their own: a column belongs to a project, and who may
 * shape it is the project's answer. This asks `Project::allowsSectionChangesBy()` rather
 * than restating the rule, so the Actions, the requests and the UI cannot drift apart.
 *
 * Creation is not here — there is no section yet to judge. `ProjectPolicy::createSection()`
 * answers that one, on the thing that does exist.
 */
class SectionPolicy
{
    public function view(User $user, Section $section): bool
    {
        return $section->project->isVisibleTo($user);
    }

    public function update(User $user, Section $section): bool
    {
        return $section->project->allowsSectionChangesBy($user, Capability::SectionUpdate);
    }

    public function delete(User $user, Section $section): bool
    {
        return $section->project->allowsSectionChangesBy($user, Capability::SectionDelete);
    }
}
