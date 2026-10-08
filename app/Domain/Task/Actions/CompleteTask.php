<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Task\Events\TaskCompleted;
use App\Domain\Task\Events\TaskReopened;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class CompleteTask
{
    public function __construct(private Dispatcher $events) {}

    public function complete(Task $task, User $actor): Task
    {
        $this->guard($task, $actor);

        if ($task->isCompleted()) {
            return $task;
        }

        $task->forceFill(['completed_at' => now(), 'completed_by' => $actor->id])->save();

        $this->events->dispatch(new TaskCompleted($task->id, $task->workspace_id, $actor->id));

        return $task;
    }

    public function reopen(Task $task, User $actor): Task
    {
        $this->guard($task, $actor);

        if (! $task->isCompleted()) {
            return $task;
        }

        $task->forceFill(['completed_at' => null, 'completed_by' => null])->save();

        $this->events->dispatch(new TaskReopened($task->id, $task->workspace_id, $actor->id));

        return $task;
    }

    private function guard(Task $task, User $actor): void
    {
        if ($actor->cannot('complete', $task)) {
            throw TaskException::cannotUpdateTask();
        }
    }
}
