<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Ancestry\ParentChain;
use App\Domain\Task\Data\UpdateTaskData;
use App\Domain\Task\Events\TaskUpdated;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * A task's own fields. Completion is `CompleteTask`, assignment is `AssignTask`, and
 * placement is Phase 070 — three separate operations because each answers a different
 * question and produces a different event.
 */
final readonly class UpdateTask
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, User $actor, UpdateTaskData $data): Task
    {
        if (! $task->workspace->membershipFor($actor)?->allows(Capability::TaskUpdate)) {
            throw TaskException::cannotUpdateTask();
        }

        $parent = $this->parentFor($task, $data->parentId);

        /*
         * Nullable columns take a null as "clear this": a description that no longer
         * applies and a due date that has been dropped are both ordinary edits, and the
         * first version of the project Action filtered them out and made the fields
         * write-once (Phase 040's review).
         */
        $task->fill([
            'description' => $data->description,
            'due_at' => $data->dueAt,
            'parent_id' => $parent?->id,
        ]);

        // Title and priority cannot be null, so a null says nothing about them.
        $task->fill(array_filter([
            'title' => $data->title,
            'priority' => $data->priority,
        ], fn (mixed $value): bool => $value !== null));

        $changed = array_keys($task->getDirty());

        if ($changed === []) {
            return $task;
        }

        $task->save();

        $this->events->dispatch(new TaskUpdated($task->id, $task->workspace_id, $changed));

        return $task;
    }

    /**
     * The new parent, proven to be a task of the same workspace, not the task itself and
     * not one of its own descendants. A cycle is not merely invalid data: every read that
     * walks the chain would run forever.
     */
    private function parentFor(Task $task, ?string $parentId): ?Task
    {
        if ($parentId === null) {
            return null;
        }

        if ($parentId === $task->id) {
            throw TaskException::cannotBeItsOwnParent();
        }

        $parent = Task::query()->whereKey($parentId)->first();

        if ($parent === null || $parent->workspace_id !== $task->workspace_id) {
            throw TaskException::parentBelongsToAnotherWorkspace();
        }

        $parentOf = $this->parentResolver($task->workspace_id);

        if (ParentChain::wouldCycle($task->id, $parent->id, $parentOf)) {
            throw TaskException::parentWouldCloseALoop();
        }

        if (ParentChain::depthOf($parent->id, $parentOf) + 1 >= ParentChain::MAX_DEPTH) {
            throw TaskException::parentChainTooDeep();
        }

        return $parent;
    }

    /**
     * Resolves parents one row at a time, memoised for the walk. A chain is bounded by
     * `ParentChain::MAX_DEPTH`, so this is a handful of primary-key lookups rather than a
     * recursive query — and it stays honest if the data already contains a loop, which the
     * walk is written to survive.
     *
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
}
