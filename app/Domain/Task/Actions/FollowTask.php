<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Task\Events\TaskFollowed;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskFollower;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Start watching a task.
 *
 * Watching is not editing: anybody who can **read** the task may follow it, which is why this
 * asks the policy's `view` rather than a capability. What it will not do is subscribe somebody
 * to a task they cannot open — the rule TASK-070-017 settled for assignment, applied to
 * notifications, where the consequence is an inbox full of work nobody can reach.
 */
final readonly class FollowTask
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, User $follower): TaskFollower
    {
        if ($follower->cannot('view', $task)) {
            throw TaskException::followerCannotReachTask();
        }

        $existing = $task->follows()->where('user_id', $follower->id)->first();

        // Following twice is following once: the same request arriving again, not a second
        // subscription.
        if ($existing instanceof TaskFollower) {
            return $existing;
        }

        try {
            $follow = $this->follow($task, $follower);
        } catch (UniqueConstraintViolationException) {
            /*
             * Two clicks, or two devices, at the same moment. `UNIQUE(task_id, user_id)` made
             * that an error rather than two rows, and the row that won is the answer.
             */
            return $task->follows()->where('user_id', $follower->id)->firstOrFail();
        }

        $this->events->dispatch(new TaskFollowed($task->id, $task->workspace_id, $follower->id));

        return $follow;
    }

    private function follow(Task $task, User $follower): TaskFollower
    {
        $follow = new TaskFollower(['task_id' => $task->id, 'user_id' => $follower->id]);
        $follow->save();

        return $follow;
    }
}
