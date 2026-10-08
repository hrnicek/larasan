<?php

declare(strict_types=1);

namespace App\Domain\Section\Data;

use App\Domain\Shared\ValueObjects\AccentColor;
use App\Http\Requests\Section\UpdateSectionRequest;

final readonly class UpdateSectionData
{
    public function __construct(
        public string $name,
        public ?AccentColor $color = null,
    ) {}

    public static function fromRequest(UpdateSectionRequest $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            color: AccentColor::tryFrom($request->string('color')->value()),
        );
    }
}
