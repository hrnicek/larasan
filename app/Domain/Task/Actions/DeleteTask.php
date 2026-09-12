<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Task\Events\TaskDeleted;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

final readonly class DeleteTask
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, User $actor): void
    {
        if ($actor->cannot('delete', $task)) {
            throw TaskException::cannotDeleteTask();
        }

        $taskId = $task->id;
        $workspaceId = $task->workspace_id;

        DB::transaction(function () use ($task): void {
            // A soft delete never triggers the foreign key's ON DELETE SET NULL, so children are detached here.
            $task->children()->update(['parent_id' => null]);

            $task->delete();
        });

        $this->events->dispatch(new TaskDeleted($taskId, $workspaceId, $actor->id));
    }
}
