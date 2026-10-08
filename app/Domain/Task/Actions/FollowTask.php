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

final readonly class FollowTask
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, User $follower): TaskFollower
    {
        if ($follower->cannot('view', $task)) {
            throw TaskException::followerCannotReachTask();
        }

        $existing = $task->follows()->where('user_id', $follower->id)->first();

        if ($existing instanceof TaskFollower) {
            return $existing;
        }

        try {
            $follow = $this->follow($task, $follower);
        } catch (UniqueConstraintViolationException) {
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
