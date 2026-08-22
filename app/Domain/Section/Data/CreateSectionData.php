<?php

declare(strict_types=1);

namespace App\Domain\Section\Data;

use App\Domain\Shared\Enums\ProjectColor;
use App\Http\Requests\Section\StoreSectionRequest;

final readonly class CreateSectionData
{
    public function __construct(
        public string $name,
        public ?ProjectColor $color = null,
    ) {}

    public static function fromRequest(StoreSectionRequest $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            color: $request->enum('color', ProjectColor::class),
        );
    }
}
