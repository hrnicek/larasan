<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Events\TaskCompleted;
use App\Domain\Task\Events\TaskReopened;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Completion is a state of the task and nothing else. No section is consulted and none can
 * be: a column called "Done" is a name somebody chose (ADR-0004), and a project that renames
 * it must not reopen anybody's work.
 *
 * Completing and reopening live together because they are one operation with two
 * directions, and splitting them would mean two copies of the same authorization check.
 */
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

        /*
         * Both columns are cleared. Leaving `completed_by` behind would make a reopened
         * task look completed to anything that reads the column instead of the timestamp.
         */
        $task->forceFill(['completed_at' => null, 'completed_by' => null])->save();

        $this->events->dispatch(new TaskReopened($task->id, $task->workspace_id, $actor->id));

        return $task;
    }

    private function guard(Task $task, User $actor): void
    {
        if (! $task->workspace->membershipFor($actor)?->allows(Capability::TaskUpdate)) {
            throw TaskException::cannotUpdateTask();
        }
    }
}
