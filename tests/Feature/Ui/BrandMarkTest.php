<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 * The mark (ADR-0018) exists twice: once as an inline Vue component drawn in `currentColor`, and
 * once as a standalone SVG in the brand plum that the whole icon family is generated from. Two
 * copies of one shape is exactly the arrangement that drifts — a mark corrected in one file and
 * not the other ships a browser tab that disagrees with the sidebar, and nobody looks at a
 * favicon closely enough to notice.
 */

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
    // The component is `currentColor` because it sits on the chrome, on the canvas and on the
    // brand colour itself. The favicon has no parent to inherit from, so it carries the hex.
    expect((string) File::get(resource_path('js/components/AppLogoIcon.vue')))
        ->toContain('fill="currentColor"')
        ->and((string) File::get(public_path('favicon.svg')))
        ->toContain('#8C2A87');
});
