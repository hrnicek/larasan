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
    // The icon family is generated from favicon.svg.
    $component = markPathData((string) File::get(resource_path('js/components/AppLogoIcon.vue')));
    $favicon = markPathData((string) File::get(public_path('favicon.svg')));

    expect($component)->toHaveCount(1)
        ->and($favicon)->toHaveCount(1)
        ->and($favicon[0])->toBe($component[0]);
});

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
});

it('draws the mark in the brand plum where it cannot inherit a colour', function (): void {
    expect((string) File::get(resource_path('js/components/AppLogoIcon.vue')))
        ->toContain('fill="currentColor"')
        ->and((string) File::get(public_path('favicon.svg')))
        ->toContain('#8C2A87');
});

it('colours the svg favicon for renderers that read stylesheets and for those that do not', function (): void {
    $favicon = (string) File::get(public_path('favicon.svg'));

    expect($favicon)
        ->toContain('fill="#8C2A87"')
        ->toContain('prefers-color-scheme: dark')
        ->toContain('#DE84D4');
});

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
});
