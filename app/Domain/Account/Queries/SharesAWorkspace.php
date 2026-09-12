<?php

declare(strict_types=1);

namespace App\Domain\Account\Queries;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;

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
