<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Html\RichText;
use App\Domain\Task\Ancestry\ParentChain;
use App\Domain\Task\Ancestry\TaskTree;
use App\Domain\Task\Data\CreateTaskData;
use App\Domain\Task\Events\TaskCreated;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

final readonly class CreateTask
{
    public function __construct(
        private Dispatcher $events,
        private TaskTree $tree,
        private AssignTask $assignTask,
    ) {}

    public function handle(Workspace $workspace, User $creator, CreateTaskData $data): Task
    {
        if (! $workspace->membershipFor($creator)?->allows(Capability::TaskCreate)) {
            throw TaskException::cannotCreateTasks();
        }

        $assignee = $data->assigneeId === null
            ? null
            : User::query()->find($data->assigneeId) ?? throw TaskException::assigneeIsNotAMember();

        return DB::transaction(function () use ($workspace, $creator, $data, $assignee): Task {
            $parent = $this->parentIn($workspace, $data->parentId);

            $task = new Task([
                'parent_id' => $parent?->id,
                'title' => $data->title,
                'description' => RichText::sanitize($data->description),
                'priority' => $data->priority,
                'due_at' => $data->dueAt,
            ]);

            $task->workspace_id = $workspace->id;
            $task->created_by = $creator->id;
            $task->save();

            $this->events->dispatch(new TaskCreated($task->id, $workspace->id, $creator->id));

            if ($assignee !== null) {
                $this->assignTask->handle($task, $creator, $assignee);
            }

            return $task;
        });
    }

    private function parentIn(Workspace $workspace, ?string $parentId): ?Task
    {
        if ($parentId === null) {
            return null;
        }

        $parent = Task::query()->whereKey($parentId)->first();

        // The self-referencing foreign key cannot enforce that the parent is in the same workspace.
        if ($parent === null || $parent->workspace_id !== $workspace->id) {
            throw TaskException::parentBelongsToAnotherWorkspace();
        }

        $parentOf = $this->tree->lockChainFrom($workspace->id, $parent->id);

        if (ParentChain::depthOf($parent->id, $parentOf) + 1 >= ParentChain::MAX_DEPTH) {
            throw TaskException::parentChainTooDeep();
        }

        return $parent;
    }
}
