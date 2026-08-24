<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 * The manifest is a route rather than a file in `public/` (TASK-190-005): a static one would carry
 * a second copy of the application's name, and the first time `APP_NAME` changed an installed app
 * would keep showing the old one.
 */

it('serves a manifest anybody can read, signed in or not', function (): void {
    $response = $this->get(route('manifest'));

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/manifest+json');
})->with([
    'the browser fetches it before anybody has signed in, so it cannot sit behind auth',
]);

it('names the application the application is called', function (): void {
    config(['app.name' => 'Something Else']);

    $this->get(route('manifest'))->assertJson([
        'name' => 'Something Else',
        'short_name' => 'Something Else',
    ]);
})->with([
    'the whole reason it is a route: a hard-coded name drifts from APP_NAME and nobody looks',
]);

it('opens on a screen that exists', function (): void {
    $start = (string) $this->get(route('manifest'))->json('start_url');

    // Signed out it redirects to login rather than 404ing, which is what an installed app needs:
    // somewhere to go, not an error to look at.
    $this->get($start)->assertRedirect(route('login'));
})->with([
    'an installed app that opens on a 404 is one nobody opens twice',
]);

it('declares the icons it ships, including a maskable one', function (): void {
    /** @var list<array{src: string, sizes: string, type: string, purpose: string}> $icons */
    $icons = $this->get(route('manifest'))->json('icons');

    expect(array_column($icons, 'sizes'))->toContain('192x192', '512x512')
        ->and(array_column($icons, 'purpose'))->toContain('maskable');

    foreach ($icons as $icon) {
        $path = public_path(ltrim($icon['src'], '/'));

        expect(File::exists($path))->toBeTrue("the manifest names {$icon['src']}, which is not there")
            ->and(File::size($path))->toBeGreaterThan(0);
    }
})->with([
    'a maskable icon that ignores the safe area is a logo with its edges cut off on Android, and
    an icon the manifest names but nobody shipped is an install prompt that never appears',
]);

it('is linked from the page the browser loads first', function (): void {
    $head = (string) File::get(resource_path('views/app.blade.php'));

    expect($head)->toContain('rel="manifest"')
        // Two theme colours: the manifest carries one, and a browser chrome painted white around
        // a dark application is the seam people notice.
        ->toContain('prefers-color-scheme: light')
        ->toContain('prefers-color-scheme: dark');
});

/**
 * GD answers with unions — `false` for a file it could not read — so the narrowing happens once,
 * here, rather than in the middle of an assertion.
 *
 * @return array{width: int, height: int, corner: array{red: int, green: int, blue: int, alpha: int}}
 */
function pngShape(string $path): array
{
    $size = getimagesize($path);
    $image = imagecreatefrompng($path);

    if ($size === false || ! $image instanceof GdImage) {
        throw new RuntimeException("{$path} is not a readable PNG");
    }

    $corner = imagecolorat($image, 3, 3);

    if ($corner === false) {
        throw new RuntimeException("{$path} has no pixel to read at its corner");
    }

    return [
        'width' => $size[0],
        'height' => $size[1],
        'corner' => imagecolorsforindex($image, $corner),
    ];
}

it('ships one icon family rather than two', function (): void {
    // iOS never reads the manifest: it takes `apple-touch-icon.png`, and it composites whatever
    // it finds onto its own background before rounding the corners itself. A transparent icon
    // therefore arrives with a colour nobody chose, and a full-bleed one does not — which is why
    // this file is opaque while the manifest's `any` icons carry their own rounding.
    $apple = public_path('apple-touch-icon.png');

    expect(File::exists($apple))->toBeTrue();

    $shape = pngShape($apple);

    expect($shape['width'])->toBe(180)
        ->and($shape['height'])->toBe(180)
        ->and($shape['corner']['alpha'])->toBe(0, 'iOS composites a transparent icon onto a colour nobody chose')
        ->and(sprintf('#%02x%02x%02x', $shape['corner']['red'], $shape['corner']['green'], $shape['corner']['blue']))
        ->toBe('#1a1a1a');
})->with([
    'the icon that predates the manifest was the red mark on transparency at 166px — a second,
    older answer to the same question, which is what this asserts is gone',
]);

it('keeps the icons a browser tab asks for', function (): void {
    // Kept rather than replaced: a tab renders these at 16px, where the mark reads and a tile
    // with a small mark inside it does not.
    expect(File::exists(public_path('favicon.ico')))->toBeTrue()
        ->and(File::exists(public_path('favicon.svg')))->toBeTrue();
});
