<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Task\Events\TaskUnfollowed;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskFollower;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Stop watching a task.
 *
 * No reach check, deliberately: somebody who has lost access to a task must still be able to
 * stop being notified about it, and refusing would leave them subscribed to something they
 * cannot open. Unfollowing something you do not follow is a no-op, because the outcome they
 * asked for is already true.
 */
final readonly class UnfollowTask
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, User $follower): void
    {
        $follow = $task->follows()->where('user_id', $follower->id)->first();

        if (! $follow instanceof TaskFollower) {
            return;
        }

        $follow->delete();

        $this->events->dispatch(new TaskUnfollowed($task->id, $task->workspace_id, $follower->id));
    }
}
