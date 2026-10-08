<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Events\TaskCollaboratorRemoved;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskCollaborator;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class RemoveTaskCollaborator
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, User $actor, int $collaboratorId): void
    {
        // Removing yourself needs no capability, so a user who lost access can still step off.
        if ($actor->id !== $collaboratorId
            && ! $task->workspace->membershipFor($actor)?->allows(Capability::TaskAssign)) {
            throw TaskException::cannotAssignTask();
        }

        $collaboration = $task->collaborations()->where('user_id', $collaboratorId)->first();

        if (! $collaboration instanceof TaskCollaborator) {
            return;
        }

        $collaboration->delete();

        $this->events->dispatch(new TaskCollaboratorRemoved($task->id, $task->workspace_id, $collaboratorId, $actor->id));
    }
}
