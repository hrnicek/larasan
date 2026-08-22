<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Enums\Capability;
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
                'description' => $data->description,
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
     * The parent, proven to be in this workspace. A self-referencing foreign key cannot
     * express "the same workspace", so nothing but an Action can refuse a parent from
     * another tenant — `TaskSchemaTest` records that the database will happily accept one.
     */
    private function parentIn(Workspace $workspace, ?string $parentId): ?Task
    {
        if ($parentId === null) {
            return null;
        }

        $parent = Task::query()->whereKey($parentId)->first();

        if ($parent === null || $parent->workspace_id !== $workspace->id) {
            throw TaskException::parentBelongsToAnotherWorkspace();
        }

        return $parent;
    }
}
