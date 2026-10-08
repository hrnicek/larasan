<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

final readonly class AssignTask
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, User $actor, ?User $assignee): Task
    {
        if ($actor->cannot('assign', $task)) {
            throw TaskException::cannotAssignTask();
        }

        // User ids are global, so the assignee must be an active member of this workspace.
        if ($assignee !== null && ! $task->workspace->hasActiveMember($assignee->id)) {
            throw TaskException::assigneeIsNotAMember();
        }

        // Membership is not reach: a guest or a member outside a private project may be unable to open the task.
        if ($assignee !== null && ! $assignee->can('view', $task)) {
            throw TaskException::assigneeCannotReachTask();
        }

        if ($task->assignee_id === $assignee?->id) {
            return $task;
        }

        DB::transaction(function () use ($task, $assignee): void {
            $task->forceFill(['assignee_id' => $assignee?->id])->save();

            if ($assignee !== null) {
                $task->collaborations()->where('user_id', $assignee->id)->delete();
            }
        });

        $this->events->dispatch(new TaskAssigned($task->id, $task->workspace_id, $assignee?->id, $actor->id));

        return $task;
    }
}
