<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskStar;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class StarTask
{
    public function handle(Task $task, User $actor): TaskStar
    {
        if ($actor->cannot('view', $task)) {
            throw TaskException::cannotStarUnreachableTask();
        }

        $existing = $task->stars()->where('user_id', $actor->id)->first();

        if ($existing instanceof TaskStar) {
            return $existing;
        }

        try {
            $star = new TaskStar(['task_id' => $task->id, 'user_id' => $actor->id]);
            $star->save();

            return $star;
        } catch (UniqueConstraintViolationException) {
            return $task->stars()->where('user_id', $actor->id)->firstOrFail();
        }
    }
}
