<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Enums\ProjectColor;
use InvalidArgumentException;
use Stringable;

/**
 * The accent a project, a column, a tag or a field's option is drawn in.
 *
 * Two kinds of value in one column, deliberately: one of the eight palette *names*, or a hex
 * somebody chose. A name is re-tuned globally without a data migration and is guaranteed
 * readable on both themes, which is why it stays the default and why every seeded and generated
 * colour is one — a hex is what somebody asks for when the eight are not the eight they wanted
 * (ADR-0021).
 *
 * Normalised on the way in: a hex is stored lower-case with its `#`, so `#AABBCC` and `#aabbcc`
 * are one colour rather than two rows that look identical.
 */
final readonly class AccentColor implements Stringable
{
    private const HEX = '/^#[0-9a-f]{6}$/';

    /** A palette name (`slate`), or a hex in lower case (`#3f7d5a`). */
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

    /**
     * The colour a request or a database row meant, or null where it named none. Null rather than
     * an exception, because "no colour" is a value this column carries and every caller has to
     * handle it anyway.
     */
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

    /** The palette case this is, or null where it is a colour the palette does not have. */
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
