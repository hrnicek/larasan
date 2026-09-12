<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Domain\Search\Actions\RecordRecentlyOpened;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait RemembersWhatWasOpened
{
    protected function rememberOpening(Workspace $workspace, User $actor, Model $subject): void
    {
        defer(fn () => app(RecordRecentlyOpened::class)->handle($workspace, $actor, $subject));
    }
}
