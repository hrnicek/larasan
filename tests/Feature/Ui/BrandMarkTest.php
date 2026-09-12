<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * @return list<string>
 */
function markPathData(string $svg): array
{
    preg_match_all('/\sd="([^"]+)"/', $svg, $matches);

    return array_map(
        static fn (string $data): string => (string) preg_replace('/\s+/', ' ', trim($data)),
        $matches[1],
    );
}

it('draws the same mark in the component and in the favicon', function (): void {
    $component = markPathData((string) File::get(resource_path('js/components/AppLogoIcon.vue')));
    $favicon = markPathData((string) File::get(public_path('favicon.svg')));

    expect($component)->toHaveCount(1)
        ->and($favicon)->toHaveCount(1)
        ->and($favicon[0])->toBe($component[0]);
})->with([
    'the icon family is generated from favicon.svg, so a mark corrected only in the component
    ships a browser tab that disagrees with the sidebar',
]);

it('keeps the mark to one path a maskable icon can survive', function (): void {
    $sources = [
        'the component' => (string) File::get(resource_path('js/components/AppLogoIcon.vue')),
        'the favicon' => (string) File::get(public_path('favicon.svg')),
    ];

    foreach ($sources as $where => $svg) {
        expect(substr_count($svg, '<path'))->toBe(1, "{$where} draws more than one path")
            ->and($svg)->not->toContain('stroke', "{$where} carries a stroke, which thins to nothing at 16px")
            ->and($svg)->not->toContain('Gradient', "{$where} carries a gradient, which a monochrome recolour cannot honour");
    }
})->with([
    'the icon family is one `-colorize` away from the source file: a stroke, a gradient or a
    second path is a shape the recipe silently flattens or loses',
]);

it('draws the mark in the brand plum where it cannot inherit a colour', function (): void {
    expect((string) File::get(resource_path('js/components/AppLogoIcon.vue')))
        ->toContain('fill="currentColor"')
        ->and((string) File::get(public_path('favicon.svg')))
        ->toContain('#8C2A87');
});

it('ships the brand kit the README points at', function (string $file): void {
    $path = base_path("docs/brand/{$file}");

    expect(File::exists($path))->toBeTrue("docs/brand/{$file} is named in the brand README and is not there")
        ->and(File::size($path))->toBeGreaterThan(0);
})->with([
    'larasan-mark.svg',
    'larasan-mark-mono.svg',
    'larasan-wordmark.svg',
    'larasan-lockup.svg',
    'larasan-lockup-dark.svg',
    'larasan-lockup-stacked.svg',
    'larasan-banner-light.png',
    'larasan-banner-dark.png',
    'larasan-og.png',
    'larasan-avatar.png',
]);

it('draws the same mark in the brand kit as in the application', function (string $file): void {
    $kit = markPathData((string) File::get(base_path("docs/brand/{$file}")));
    $component = markPathData((string) File::get(resource_path('js/components/AppLogoIcon.vue')));

    expect($kit)->toContain($component[0]);
})->with(['larasan-mark.svg', 'larasan-mark-mono.svg']);

it('sets the wordmark as outlines rather than as text', function (): void {
    // GitHub does not load fonts for SVG, so live text would render in a fallback font.
    $wordmark = (string) File::get(base_path('docs/brand/larasan-wordmark.svg'));

    expect($wordmark)->not->toContain('<text')
        ->and($wordmark)->not->toContain('font-family')
        ->and($wordmark)->toContain('<path');
})->with([
    'a wordmark set as live text is a wordmark drawn in a font the reader happens to have',
]);

it('colours the svg favicon for renderers that read stylesheets and for those that do not', function (): void {
    $favicon = (string) File::get(public_path('favicon.svg'));

    expect($favicon)
        ->toContain('fill="#8C2A87"')
        ->toContain('prefers-color-scheme: dark')
        ->toContain('#DE84D4');
})->with([
    'a favicon whose only colour is in a stylesheet is a favicon some renderers draw as nothing',
]);

it('carries hinted bitmaps for the sizes a rasteriser gets wrong', function (): void {
    $ico = (string) File::get(public_path('favicon.ico'));

    // ICONDIR is 6 bytes (reserved, type, count); each 16-byte ICONDIRENTRY starts with width, where 0 means 256.
    /** @var array{reserved: int, type: int, count: int} $header */
    $header = unpack('vreserved/vtype/vcount', $ico);

    expect($header['reserved'])->toBe(0)
        ->and($header['type'])->toBe(1);

    $sizes = [];

    for ($i = 0; $i < $header['count']; $i++) {
        $entry = substr($ico, 6 + $i * 16, 16);
        $sizes[] = ord($entry[0]) === 0 ? 256 : ord($entry[0]);
    }

    expect($sizes)->toContain(16, 32, 48);
})->with([
    'the 24-unit grid puts a 3.2 bar on 2.13 pixels at 16px, so every edge arrives grey unless
    something is drawn on the pixel grid instead',
]);
