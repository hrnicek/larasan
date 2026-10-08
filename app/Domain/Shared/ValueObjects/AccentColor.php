<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Enums\ProjectColor;
use InvalidArgumentException;
use Stringable;

final readonly class AccentColor implements Stringable
{
    private const HEX = '/^#[0-9a-f]{6}$/';

    public string $value;

    public function __construct(string $value)
    {
        $normalised = mb_strtolower(trim($value));

        if (! self::isValid($normalised)) {
            throw new InvalidArgumentException("[{$value}] is neither a palette colour nor a hex colour.");
        }

        $this->value = $normalised;
    }

    public static function palette(ProjectColor $color): self
    {
        return new self($color->value);
    }

    public static function tryFrom(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $normalised = mb_strtolower(trim($value));

        return self::isValid($normalised) ? new self($normalised) : null;
    }

    public static function isValid(string $value): bool
    {
        return ProjectColor::tryFrom($value) instanceof ProjectColor
            || preg_match(self::HEX, $value) === 1;
    }

    public function paletteColor(): ?ProjectColor
    {
        return ProjectColor::tryFrom($this->value);
    }

    public function isCustom(): bool
    {
        return ! $this->paletteColor() instanceof ProjectColor;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
