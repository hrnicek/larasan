<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Html\RichText;
use App\Domain\Task\Ancestry\ParentChain;
use App\Domain\Task\Ancestry\TaskTree;
use App\Domain\Task\Data\UpdateTaskData;
use App\Domain\Task\Events\TaskUpdated;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTask
{
    public function __construct(private Dispatcher $events, private TaskTree $tree) {}

    public function handle(Task $task, User $actor, UpdateTaskData $data): Task
    {
        if ($actor->cannot('update', $task)) {
            throw TaskException::cannotUpdateTask();
        }

        $changed = DB::transaction(function () use ($task, $actor, $data): array {
            $parent = $data->changes('parent_id') ? $this->parentFor($task, $actor, $data->parentId) : null;

            // A null clears a nullable field; a field absent from the payload is left untouched.
            $task->fill($this->changed($data, [
                // Sanitised here rather than in the FormRequest so console and queue callers are covered too.
                'description' => RichText::sanitize($data->description),
                'due_at' => $data->dueAt,
                'parent_id' => $parent?->id,
            ]));

            $task->fill(array_filter(
                $this->changed($data, ['title' => $data->title, 'priority' => $data->priority]),
                fn (mixed $value): bool => $value !== null,
            ));

            $changed = array_keys($task->getDirty());

            if ($changed !== []) {
                $task->save();
            }

            return $changed;
        });

        if ($changed === []) {
            return $task;
        }

        $this->events->dispatch(new TaskUpdated($task->id, $task->workspace_id, $changed, $actor->id));

        return $task;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function changed(UpdateTaskData $data, array $values): array
    {
        return array_filter(
            $values,
            fn (string $field): bool => $data->changes($field),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function parentFor(Task $task, User $actor, ?string $parentId): ?Task
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

        // Moving a task under a parent puts it in that parent's project (ADR-0023).
        if ($actor->cannot('update', $parent)) {
            throw TaskException::cannotChangeParent();
        }

        $parentOf = $this->tree->lockChainFrom($task->workspace_id, $parent->id, alsoLock: $task->id);

        if (ParentChain::wouldCycle($task->id, $parent->id, $parentOf)) {
            throw TaskException::parentWouldCloseALoop();
        }

        $deepest = ParentChain::depthOf($parent->id, $parentOf) + 1 + $this->tree->heightOf($task->workspace_id, $task->id);

        if ($deepest >= ParentChain::MAX_DEPTH) {
            throw TaskException::parentChainTooDeep();
        }

        return $parent;
    }
}
