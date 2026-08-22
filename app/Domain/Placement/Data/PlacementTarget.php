<?php

declare(strict_types=1);

namespace App\Domain\Placement\Data;

use App\Domain\Placement\Models\TaskProjectMembership;

/**
 * Where in a column a card lands. A client never sends a position (ADR-0009), so the three
 * things it can actually mean are named here instead: the end of the column, the front of
 * it, or immediately after a card it can see.
 *
 * `MoveSection` expresses the same idea as a nullable `?Section $after`, where null means
 * the front. A board has one more case than a column list does — dropping a card at the
 * bottom is not the same request as dropping it at the top — and a second nullable
 * parameter would have made the two indistinguishable.
 */
final readonly class PlacementTarget
{
    private function __construct(
        public ?TaskProjectMembership $after,
        public bool $atFront,
    ) {}

    /** The end of the column: where a card goes when nothing says otherwise. */
    public static function end(): self
    {
        return new self(after: null, atFront: false);
    }

    public static function front(): self
    {
        return new self(after: null, atFront: true);
    }

    public static function after(TaskProjectMembership $placement): self
    {
        return new self(after: $placement, atFront: false);
    }
}
