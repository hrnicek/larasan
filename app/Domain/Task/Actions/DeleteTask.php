<?php

declare(strict_types=1);

namespace App\Domain\Task\Actions;

use App\Domain\Task\Ancestry\ParentChain;
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

        $workspaceId = $task->workspace_id;

        $deletedIds = DB::transaction(function () use ($task): array {
            // Unplaced subtasks take their reach from this task, so promoting them would widen who can read them. See ADR-0023.
            $deleting = $this->withUnplacedDescendants($task);

            // A soft delete never triggers the foreign key's ON DELETE SET NULL, so the placed subtasks are promoted here.
            Task::query()
                ->where('workspace_id', $task->workspace_id)
                ->whereIn('parent_id', $deleting)
                ->whereNotIn('id', $deleting)
                ->update(['parent_id' => null]);

            Task::query()->whereKey($deleting)->get()->each(fn (Task $doomed): ?bool => $doomed->delete());

            return $deleting;
        });

        foreach ($deletedIds as $deletedId) {
            $this->events->dispatch(new TaskDeleted($deletedId, $workspaceId, $actor->id));
        }
    }

    /**
     * @return list<string>
     */
    private function withUnplacedDescendants(Task $task): array
    {
        $ids = [$task->id];
        $level = [$task->id];

        for ($depth = 0; $depth < ParentChain::MAX_DEPTH && $level !== []; $depth++) {
            $level = array_values(Task::query()
                ->where('workspace_id', $task->workspace_id)
                ->whereIn('parent_id', $level)
                ->whereNotIn('id', $ids)
                ->whereDoesntHave('placements')
                ->pluck('id')
                ->all());

            $ids = [...$ids, ...$level];
        }

        return $ids;
    }
}
