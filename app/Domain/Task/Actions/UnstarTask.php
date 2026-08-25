<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskStar;
use App\Models\User;

/**
 * Stop keeping a task at hand.
 *
 * No reach check, as `UnfollowTask` has none: somebody who has lost access to a task must still
 * be able to clear it out of their own Starred tab. Unstarring something that is not starred is a
 * no-op, because the outcome they asked for is already true.
 */
final readonly class UnstarTask
{
    public function handle(Task $task, User $actor): void
    {
        $star = $task->stars()->where('user_id', $actor->id)->first();

        if (! $star instanceof TaskStar) {
            return;
        }

        $star->delete();
    }
}
