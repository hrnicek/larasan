<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Enums\Capability;
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
     * The new parent, proven to be a task of the same workspace and not the task itself.
     * A longer cycle is TASK-060-014's problem and is refused there; this refuses the two
     * cases that need no traversal.
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

        return $parent;
    }
}
