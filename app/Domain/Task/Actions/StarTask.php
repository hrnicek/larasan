<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskStar;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Keep a task at hand.
 *
 * Starring is not editing and it is not following: anybody who can **read** the task may star it,
 * and nobody is notified about anything as a result. What it will not do is put a task somebody
 * cannot open into their own Starred tab — the reach rule `FollowTask` applies to notifications,
 * applied here to a list.
 */
final readonly class StarTask
{
    public function handle(Task $task, User $actor): TaskStar
    {
        if ($actor->cannot('view', $task)) {
            throw TaskException::cannotStarUnreachableTask();
        }

        $existing = $task->stars()->where('user_id', $actor->id)->first();

        // Starring twice is starring once: the same request arriving again, not a second star.
        if ($existing instanceof TaskStar) {
            return $existing;
        }

        try {
            $star = new TaskStar(['task_id' => $task->id, 'user_id' => $actor->id]);
            $star->save();

            return $star;
        } catch (UniqueConstraintViolationException) {
            /*
             * Two clicks, or two devices, at the same moment. `UNIQUE(task_id, user_id)` made that
             * an error rather than two rows, and the row that won is the answer.
             */
            return $task->stars()->where('user_id', $actor->id)->firstOrFail();
        }
    }
}
