<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * The surface scheme the application is drawn on.
 *
 * This is a second axis, not a replacement for one. Appearance (light / dark / system) decides
 * how bright the screen is and belongs to the device; the theme decides what the surfaces are
 * made of and belongs to the person. Every combination of the two is a real state and every one
 * of them has a token block.
 *
 * **A theme owns surfaces, never the brand.** `--primary` and its steps, `--ring`,
 * `--destructive` and the eight-colour accent palette are the same in every theme, because
 * ADR-0014 chose plum at hue 330 for its angular distance from the colours that label projects,
 * sections and tags — 37° from `violet`, 46° from `rose`. A theme that moved the accent would put
 * a second hue somewhere in that gap and make a project dot look like the primary action. See
 * ADR-0019.
 *
 * The value is written on `<html data-theme="...">` server-side and `resources/css/app.css` holds
 * the matching blocks. Nothing else in the application knows a colour.
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
            self::Slate => 'Slate',
            self::Meridian => 'Meridian',
            self::Ember => 'Ember',
            self::Nocturne => 'Nocturne',
            self::Moss => 'Moss',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Slate => 'Cool neutral surfaces under a graphite rail. The original.',
            self::Meridian => 'Petrol on cool white. Crisp, and the closest to a drawing board.',
            self::Ember => 'Burnt umber on ivory. The one to use in a room lit by a lamp.',
            self::Nocturne => 'Deep indigo, the darkest rail of the five, on a paper with the same blue in it.',
            self::Moss => 'Forest on a trace of sage. The quietest scheme that still has a colour.',
        };
    }

    /**
     * The default theme's tokens live in `:root` and `.dark` rather than in a block of their own,
     * so it is the one case with nothing to select.
     */
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
