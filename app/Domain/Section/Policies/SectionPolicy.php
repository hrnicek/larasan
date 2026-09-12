<?php

declare(strict_types=1);

namespace App\Domain\Section\Policies;

use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Models\User;

class SectionPolicy
{
    public function view(User $user, Section $section): bool
    {
        return $section->project->isVisibleTo($user);
    }

    public function update(User $user, Section $section): bool
    {
        return $section->project->allowsChangesBy($user, Capability::SectionUpdate);
    }

    public function delete(User $user, Section $section): bool
    {
        return $section->project->allowsChangesBy($user, Capability::SectionDelete);
    }
}
