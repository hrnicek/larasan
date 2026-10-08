<?php

declare(strict_types=1);

namespace App\Domain\Task\Ancestry;

final readonly class ParentChain
{
    public const MAX_DEPTH = 10;

    /**
     * @param  callable(string): ?string  $parentOf  the parent id of a task, or null
     */
    public static function wouldCycle(string $taskId, string $parentId, callable $parentOf): bool
    {
        if ($taskId === $parentId) {
            return true;
        }

        $seen = [];
        $current = $parentId;

        while ($current !== null) {
            if ($current === $taskId) {
                return true;
            }

            // Stops on a loop already present in the data, which would otherwise never reach null.
            if (isset($seen[$current])) {
                return true;
            }

            $seen[$current] = true;
            $current = $parentOf($current);
        }

        return false;
    }

    /**
     * @param  callable(string): ?string  $parentOf
     */
    public static function depthOf(string $taskId, callable $parentOf): int
    {
        $depth = 0;
        $seen = [];
        $current = $parentOf($taskId);

        while ($current !== null && ! isset($seen[$current])) {
            $seen[$current] = true;
            $depth++;
            $current = $parentOf($current);
        }

        return $depth;
    }
}
