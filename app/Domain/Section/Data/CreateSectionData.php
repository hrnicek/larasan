<?php

declare(strict_types=1);

namespace App\Domain\Section\Data;

use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\ValueObjects\AccentColor;
use App\Http\Requests\Section\StoreSectionRequest;

final readonly class CreateSectionData
{
    public const DEFAULT_COLOR = ProjectColor::Slate;

    public function __construct(
        public string $name,
        public ?AccentColor $color = new AccentColor(self::DEFAULT_COLOR->value),
    ) {}

    public static function fromRequest(StoreSectionRequest $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            color: AccentColor::tryFrom($request->string('color')->value()) ?? AccentColor::palette(self::DEFAULT_COLOR),
        );
    }
}
