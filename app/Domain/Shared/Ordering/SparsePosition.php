<?php

declare(strict_types=1);

namespace App\Domain\Shared\Ordering;

/**
 * The ordering arithmetic from ADR-0009, with no database behind it: sections and task
 * placements both order this way, and a second copy of these rules would be the one that
 * drifts.
 *
 * Positions are sparse integers. Appending leaves a gap, inserting between two neighbours
 * takes the midpoint, and a move writes exactly one row instead of rewriting the tail.
 * When the gap between neighbours collapses there is no midpoint left to take, and the
 * caller normalises the whole set rather than inventing a position.
 */
final readonly class SparsePosition
{
    public const GAP = 65536;

    /**
     * Below this, the midpoint is too close to its neighbours to be worth taking: the next
     * insertion at the same point would have nowhere to go, so the set is normalised now
     * rather than one move later.
     */
    public const MINIMUM_GAP = 16;

    /**
     * The next position after the last one, or the first position in an empty set.
     */
    public static function append(?int $last): int
    {
        return $last === null ? self::GAP : $last + self::GAP;
    }

    /**
     * Whether there is still room between two neighbours. A null neighbour is the start or
     * the end of the set, where there is always room unless the set begins below the
     * minimum gap.
     */
    public static function hasRoomBetween(?int $before, ?int $after): bool
    {
        if ($after === null) {
            return true;
        }

        if ($before === null) {
            return $after >= self::MINIMUM_GAP * 2;
        }

        return $after - $before >= self::MINIMUM_GAP * 2;
    }

    /**
     * The position between two neighbours, either of which may be null for the ends of the
     * set. Callers ask `hasRoomBetween()` first — this throws rather than returning a
     * position that collides with a neighbour, because a silent collision is the failure
     * ADR-0009's unique constraint exists to make impossible.
     *
     * @throws PositionsNeedNormalisation
     */
    public static function between(?int $before, ?int $after): int
    {
        if (! self::hasRoomBetween($before, $after)) {
            throw new PositionsNeedNormalisation;
        }

        if ($after === null) {
            return self::append($before);
        }

        return intdiv(($before ?? 0) + $after, 2);
    }

    /**
     * An even spread for `$count` rows: what normalisation rewrites the set to.
     *
     * @return list<int>
     */
    public static function spread(int $count): array
    {
        return $count < 1
            ? []
            : array_map(fn (int $index): int => ($index + 1) * self::GAP, range(0, $count - 1));
    }

    /**
     * Where a row waits while the set is being rewritten. `UNIQUE(project_id, position)`
     * means a normalisation that writes final positions directly can collide with a row it
     * has not moved yet, so every row is parked in negative space first and then written to
     * its final position. Two writes per row, only while normalising, and no ordering to
     * get right.
     */
    public static function parking(int $index): int
    {
        return -($index + 1);
    }
}
