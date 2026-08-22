<?php

declare(strict_types=1);

namespace App\Domain\Task\Ancestry;

/**
 * Whether a proposed parent would close a loop, and how deep the chain already is. Pure
 * logic with a resolver passed in, so the rule can be tested without a database and the
 * Action decides where the parents come from.
 *
 * A cycle is not merely invalid data: every read that walks the chain — a breadcrumb, a
 * progress roll-up, a delete — would run forever.
 */
final readonly class ParentChain
{
    /**
     * How many levels of subtask the application supports. Stated rather than discovered:
     * without a limit, a chain grows until something that walks it becomes the slowest
     * page in the product, and nobody can say what the intended shape was.
     */
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

            // Corrupt data would otherwise spin here: a chain that already contains a loop
            // never reaches a null.
            if (isset($seen[$current])) {
                return true;
            }

            $seen[$current] = true;
            $current = $parentOf($current);
        }

        return false;
    }

    /**
     * The number of ancestors above a task, counting from zero for a root.
     *
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
