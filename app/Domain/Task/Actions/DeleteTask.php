<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Events\TaskDeleted;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Deleting is soft: comments, attachments and history outlive the row, and a task deleted
 * by mistake is recoverable.
 *
 * The subtasks are detached rather than deleted with it — the decision the migration
 * already makes for a hard delete, made the same way here so the two cannot disagree. A
 * subtask can be assigned to somebody else entirely, and taking it away because its parent
 * was removed deletes work nobody asked to delete. They become root tasks.
 */
final readonly class DeleteTask
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, User $actor): void
    {
        if (! $task->workspace->membershipFor($actor)?->allows(Capability::TaskDelete)) {
            throw TaskException::cannotDeleteTask();
        }

        $taskId = $task->id;
        $workspaceId = $task->workspace_id;

        DB::transaction(function () use ($task): void {
            // Direct children only. A grandchild stays where it is: its own parent is still
            // there, and promoting a whole subtree would flatten a structure nobody asked
            // to flatten.
            $task->children()->update(['parent_id' => null]);

            $task->delete();
        });

        $this->events->dispatch(new TaskDeleted($taskId, $workspaceId, $actor->id));
    }
}
