<?php

declare(strict_types=1);

namespace App\Domain\Account\Queries;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;

/**
 * Whether one person may see another's uploaded face: they are the same person, or both hold an
 * active membership in one workspace.
 *
 * The same boundary the rest of the application draws around a person — `PersonResults` finds
 * colleagues by it — so an avatar is visible to exactly the people who are shown the name
 * beside it. An unanswered invitation is not a membership yet, and grants nothing here either.
 */
final readonly class SharesAWorkspace
{
    public function __invoke(User $viewer, User $person): bool
    {
        if ($viewer->is($person)) {
            return true;
        }

        return WorkspaceMembership::query()
            ->where('user_id', $viewer->id)
            ->where('status', WorkspaceMembershipStatus::Active->value)
            ->whereIn('workspace_id', WorkspaceMembership::query()
                ->select('workspace_id')
                ->where('user_id', $person->id)
                ->where('status', WorkspaceMembershipStatus::Active->value))
            ->exists();
    }
}
