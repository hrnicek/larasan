<?php

declare(strict_types=1);

namespace App\Domain\Task\Ancestry;

use App\Domain\Task\Models\Task;

final readonly class TaskTree
{
    /**
     * Locks the task, every ancestor above it and the extra task, in key order, so two concurrent
     * re-parents cannot both pass the cycle and depth checks. Must run inside a transaction.
     *
     * @return callable(string): ?string the parent id of a locked task
     */
    public function lockChainFrom(string $workspaceId, string $taskId, ?string $alsoLock = null): callable
    {
        /** @var array<string, string|null> $locked */
        $locked = [];
        $wanted = null;

        while (true) {
            $ids = $this->chainFrom($workspaceId, $taskId, $locked);

            if ($alsoLock !== null && ! in_array($alsoLock, $ids, true)) {
                $ids[] = $alsoLock;
            }

            sort($ids);

            // The chain was read before its rows were locked; repeat until the locked rows describe all of it.
            if ($ids === $wanted) {
                break;
            }

            $wanted = $ids;

            /** @var array<string, string|null> $locked */
            $locked = Task::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('parent_id', 'id')
                ->all();
        }

        return fn (string $id): ?string => $locked[$id] ?? null;
    }

    /**
     * The number of levels of subtasks below the task, capped at the depth limit.
     */
    public function heightOf(string $workspaceId, string $taskId): int
    {
        $height = 0;
        $level = [$taskId];

        while ($height < ParentChain::MAX_DEPTH) {
            /** @var list<string> $level */
            $level = Task::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('parent_id', $level)
                ->pluck('id')
                ->all();

            if ($level === []) {
                break;
            }

            $height++;
        }

        return $height;
    }

    /**
     * @param  array<string, string|null>  $known
     * @return list<string>
     */
    private function chainFrom(string $workspaceId, string $taskId, array $known): array
    {
        $ids = [];
        $current = $taskId;

        while ($current !== null && ! in_array($current, $ids, true)) {
            $ids[] = $current;

            $current = array_key_exists($current, $known)
                ? $known[$current]
                : $this->parentOf($workspaceId, $current);
        }

        return $ids;
    }

    private function parentOf(string $workspaceId, string $taskId): ?string
    {
        $parentId = Task::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($taskId)
            ->value('parent_id');

        return is_string($parentId) ? $parentId : null;
    }
}
