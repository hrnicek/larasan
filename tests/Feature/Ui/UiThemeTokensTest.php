<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\UiTheme;
use Illuminate\Support\Facades\File;

/**
 * @return array<string, string>
 */
function themeTokens(string $selector): array
{
    $css = (string) File::get(resource_path('css/app.css'));

    preg_match('/'.preg_quote($selector, '/').'\s*\{(.*?)\n\}/s', $css, $block);

    if ($block === []) {
        return [];
    }

    preg_match_all('/(--[\w-]+):\s*([^;]+);/', $block[1], $found, PREG_SET_ORDER);

    return array_column($found, 2, 1);
}

/**
 * WCAG relative luminance; surfaces are compared by lightness because the contrast ratio
 * compresses in dark shades. See ADR-0014.
 */
function surfaceLuminance(string $value): float
{
    $linear = static function (float $c): float {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };

    if (preg_match('/oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)/', $value, $m) === 1) {
        [$L, $C, $H] = [(float) $m[1], (float) $m[2], (float) $m[3]];

        $a = $C * cos(deg2rad($H));
        $b = $C * sin(deg2rad($H));

        $l = ($L + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m_ = ($L - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($L - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $r = 4.0767416621 * $l - 3.3077115913 * $m_ + 0.2309699292 * $s;
        $g = -1.2684380046 * $l + 2.6097574011 * $m_ - 0.3413193965 * $s;
        $bl = -0.0041960863 * $l - 0.7034186147 * $m_ + 1.7076147010 * $s;

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $bl;
    }

    if (preg_match('/hsl\(\s*([\d.]+)\s+([\d.]+)%\s+([\d.]+)%/', $value, $m) === 1) {
        [$h, $s, $l] = [(float) $m[1], (float) $m[2] / 100, (float) $m[3] / 100];

        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $o = $l - $c / 2;

        $rgb = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return 0.2126 * $linear($rgb[0] + $o) + 0.7152 * $linear($rgb[1] + $o) + 0.0722 * $linear($rgb[2] + $o);
    }

    throw new RuntimeException("cannot read a colour out of \"{$value}\"");
}

/**
 * @return array<string, string> selector => label, one per theme and mode
 */
function themeSelectors(): array
{
    $selectors = [':root' => 'slate, light', ':root.dark' => 'slate, dark'];

    foreach (UiTheme::cases() as $theme) {
        if (! $theme->hasTokenBlock()) {
            continue;
        }

        $selectors["[data-theme='{$theme->value}']"] = "{$theme->value}, light";
        $selectors["[data-theme='{$theme->value}'].dark"] = "{$theme->value}, dark";
    }

    return $selectors;
}

// A token missing from a theme's dark block silently falls back to that theme's light value.
test('every theme declares the whole surface set in both modes', function (): void {
    $reference = collect(UiTheme::cases())->first(fn (UiTheme $theme): bool => $theme->hasTokenBlock())
        ?? throw new RuntimeException('no theme carries a token block');

    $owned = array_keys(themeTokens("[data-theme='{$reference->value}']"));

    expect($owned)->not->toBeEmpty();

    foreach (themeSelectors() as $selector => $label) {
        if ($selector === ':root' || $selector === ':root.dark') {
            continue;
        }

        $declared = array_keys(themeTokens($selector));
        $missing = array_diff($owned, $declared);

        expect($missing)->toBeEmpty("{$label} does not declare: ".implode(', ', $missing));
    }
});

// A per-theme accent would collide with the label colours the brand hue is chosen to avoid. See ADR-0019.
test('no theme moves the brand', function (): void {
    $forbidden = ['--primary', '--ring', '--destructive', '--chart-'];

    foreach (themeSelectors() as $selector => $label) {
        if ($selector === ':root' || $selector === ':root.dark') {
            continue;
        }

        foreach (array_keys(themeTokens($selector)) as $token) {
            foreach ($forbidden as $prefix) {
                expect(str_starts_with($token, $prefix))->toBeFalse(
                    "{$label} declares {$token}, which belongs to the brand rather than to a theme"
                );
            }
        }
    }
});

test('the chrome stays darker than the canvas in every theme and mode', function (): void {
    foreach (themeSelectors() as $selector => $label) {
        $tokens = themeTokens($selector);

        expect($tokens)->not->toBeEmpty("{$label} has no block at all");

        $chrome = surfaceLuminance($tokens['--chrome']);
        $canvas = surfaceLuminance($tokens['--background']);
        $card = surfaceLuminance($tokens['--card']);

        expect($chrome)->toBeLessThan($canvas, "{$label}: the chrome is not darker than the canvas")
            ->and($canvas)->toBeLessThanOrEqual($card, "{$label}: the card sits below the canvas");
    }
});
