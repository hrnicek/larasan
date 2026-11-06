<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Each non-default case needs a data-theme token block in resources/css/app.css. Themes restyle
 * surfaces only, never the brand or accent tokens. See ADR-0019.
 */
enum UiTheme: string
{
    case Slate = 'slate';
    case Meridian = 'meridian';
    case Ember = 'ember';
    case Nocturne = 'nocturne';
    case Moss = 'moss';

    public static function default(): self
    {
        return self::Slate;
    }

    public function label(): string
    {
        return match ($this) {
            self::Slate => __('Slate'),
            self::Meridian => __('Meridian'),
            self::Ember => __('Ember'),
            self::Nocturne => __('Nocturne'),
            self::Moss => __('Moss'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Slate => __('Cool neutral surfaces under a graphite rail. The original.'),
            self::Meridian => __('Petrol on cool white. Crisp, and the closest to a drawing board.'),
            self::Ember => __('Burnt umber on ivory. The one to use in a room lit by a lamp.'),
            self::Nocturne => __('Deep indigo, the darkest rail of the five, on a paper with the same blue in it.'),
            self::Moss => __('Forest on a trace of sage. The quietest scheme that still has a colour.'),
        };
    }

    /** The default theme's tokens live in :root and .dark rather than a data-theme block. */
    public function hasTokenBlock(): bool
    {
        return $this !== self::default();
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'description' => $case->description(),
            ],
            self::cases(),
        );
    }
}
