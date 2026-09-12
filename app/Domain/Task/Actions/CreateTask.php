<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Html\RichText;
use App\Domain\Task\Ancestry\ParentChain;
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
    public function __construct(private Dispatcher $events) {}

    public function handle(Workspace $workspace, User $creator, CreateTaskData $data): Task
    {
        if (! $workspace->membershipFor($creator)?->allows(Capability::TaskCreate)) {
            throw TaskException::cannotCreateTasks();
        }

        $parent = $this->parentIn($workspace, $data->parentId);

        if ($data->assigneeId !== null && ! $workspace->hasActiveMember($data->assigneeId)) {
            throw TaskException::assigneeIsNotAMember();
        }

        $task = DB::transaction(function () use ($workspace, $creator, $data, $parent): Task {
            $task = new Task([
                'parent_id' => $parent?->id,
                'title' => $data->title,
                'description' => RichText::sanitize($data->description),
                'priority' => $data->priority,
                'due_at' => $data->dueAt,
                'assignee_id' => $data->assigneeId,
            ]);

            $task->workspace_id = $workspace->id;
            $task->created_by = $creator->id;
            $task->save();

            return $task;
        });

        $this->events->dispatch(new TaskCreated($task->id, $workspace->id, $creator->id));

        return $task;
    }

    /**
     * @return callable(string): ?string
     */
    private function parentResolver(string $workspaceId): callable
    {
        /** @var array<string, string|null> $resolved */
        $resolved = [];

        return function (string $id) use (&$resolved, $workspaceId): ?string {
            if (! array_key_exists($id, $resolved)) {
                $resolved[$id] = Task::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($id)
                    ->value('parent_id');
            }

            return $resolved[$id];
        };
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

        if (ParentChain::depthOf($parent->id, $this->parentResolver($workspace->id)) + 1 >= ParentChain::MAX_DEPTH) {
            throw TaskException::parentChainTooDeep();
        }

        return $parent;
    }
}
