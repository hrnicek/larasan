<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Html\RichText;
use App\Domain\Task\Ancestry\ParentChain;
use App\Domain\Task\Data\UpdateTaskData;
use App\Domain\Task\Events\TaskUpdated;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class UpdateTask
{
    public function __construct(private Dispatcher $events) {}

    public function handle(Task $task, User $actor, UpdateTaskData $data): Task
    {
        if (! $task->workspace->membershipFor($actor)?->allows(Capability::TaskUpdate)) {
            throw TaskException::cannotUpdateTask();
        }

        $parent = $data->changes('parent_id') ? $this->parentFor($task, $data->parentId) : null;

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

        if ($changed === []) {
            return $task;
        }

        $task->save();

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
