<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Task\Events\TaskUnfollowed;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskFollower;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class UnfollowTask
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, User $follower): void
    {
        // No view check, so a user who lost access to the task can still unsubscribe.
        $follow = $task->follows()->where('user_id', $follower->id)->first();

        if (! $follow instanceof TaskFollower) {
            return;
        }

        $follow->delete();

        $this->events->dispatch(new TaskUnfollowed($task->id, $task->workspace_id, $follower->id));
    }
}
