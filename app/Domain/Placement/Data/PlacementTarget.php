<?php

declare(strict_types=1);

namespace App\Domain\Placement\Data;

use App\Domain\Placement\Models\TaskProjectMembership;

final readonly class PlacementTarget
{
    private function __construct(
        public ?TaskProjectMembership $after,
        public bool $atFront,
    ) {}

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
