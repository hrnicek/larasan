<?php

declare(strict_types=1);

namespace App\Domain\Section\Data;

use App\Domain\Shared\Enums\ProjectColor;
use App\Http\Requests\Section\StoreSectionRequest;

final readonly class CreateSectionData
{
    /**
     * Slate rather than nothing, because a column with no colour has no band at all and reads as
     * a column somebody forgot rather than a neutral one. The palette can still clear it
     * afterwards — this is what a column starts as, not what it is stuck with.
     */
    public const DEFAULT_COLOR = ProjectColor::Slate;

    public function __construct(
        public string $name,
        public ?ProjectColor $color = self::DEFAULT_COLOR,
    ) {}

    public static function fromRequest(StoreSectionRequest $request): self
    {
        return new self(
            name: $request->string('name')->toString(),
            // An absent colour is a column being added without an opinion about one, which is
            // every column added from the board's own menu.
            color: $request->enum('color', ProjectColor::class) ?? self::DEFAULT_COLOR,
        );
    }
}
