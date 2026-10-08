<?php

declare(strict_types=1);

namespace App\Domain\Shared\Ordering;

final readonly class SparsePosition
{
    public const GAP = 65536;

    // Normalise early, before the next insert at the same point would find no room.
    public const MINIMUM_GAP = 16;

    public static function append(?int $last): int
    {
        return $last === null ? self::GAP : $last + self::GAP;
    }

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
     * @return list<int>
     */
    public static function spread(int $count): array
    {
        return $count < 1
            ? []
            : array_map(fn (int $index): int => ($index + 1) * self::GAP, range(0, $count - 1));
    }

    /**
     * Rows are parked at negative positions first so normalising never violates the unique
     * position constraint.
     */
    public static function parking(int $index): int
    {
        return -($index + 1);
    }
}
