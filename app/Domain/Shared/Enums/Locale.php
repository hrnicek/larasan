<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * English is the source language: its strings are the translation keys, so it has no lang/en.json.
 * Every other case needs lang/<value>.json and the framework files under lang/<value>/.
 */
enum Locale: string
{
    case English = 'en';
    case Czech = 'cs';

    public static function default(): self
    {
        return self::tryFrom((string) config('app.locale')) ?? self::English;
    }

    /** Each language is named in itself, so a person who cannot read the current one still finds theirs. */
    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Czech => 'Čeština',
        };
    }

    public function isSource(): bool
    {
        return $this === self::English;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
