<?php

declare(strict_types=1);

namespace App\Domain\Section\Data;

use App\Domain\Shared\Enums\ProjectColor;

/**
 * The position is absent by design: a move is `MoveSection`, expressed as "place this
 * after that one" rather than as a raw position the caller computed (ADR-0009).
 */
final readonly class UpdateSectionData
{
    public function __construct(
        public string $name,
        public ?ProjectColor $color = null,
    ) {}
}
