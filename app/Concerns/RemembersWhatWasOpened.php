<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Domain\Search\Actions\RecordRecentlyOpened;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What the palette offers before anybody has typed (TASK-210-008).
 *
 * Deferred, so remembering never costs the request that is drawing the screen: the write is
 * bookkeeping, and a person waiting on it would be waiting for something they cannot see. It runs
 * after the response has gone out, in the same process — a queue would be a job per screen for a
 * single upsert.
 */
trait RemembersWhatWasOpened
{
    protected function rememberOpening(Workspace $workspace, User $actor, Model $subject): void
    {
        defer(fn () => app(RecordRecentlyOpened::class)->handle($workspace, $actor, $subject));
    }
}
