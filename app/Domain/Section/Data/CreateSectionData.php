<?php

declare(strict_types=1);

namespace App\Domain\Section\Data;

use App\Domain\Shared\Enums\ProjectColor;

final readonly class CreateSectionData
{
    public function __construct(
        public string $name,
        public ?ProjectColor $color = null,
    ) {}
}
