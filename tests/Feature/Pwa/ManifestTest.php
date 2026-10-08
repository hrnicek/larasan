<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('serves a manifest anybody can read, signed in or not', function (): void {
    $response = $this->get(route('manifest'));

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/manifest+json');
});

it('names the application the application is called', function (): void {
    config(['app.name' => 'Something Else']);

    $this->get(route('manifest'))->assertJson([
        'name' => 'Something Else',
        'short_name' => 'Something Else',
    ]);
});

it('opens on a screen that exists', function (): void {
    $start = (string) $this->get(route('manifest'))->json('start_url');

    $this->get($start)->assertRedirect(route('login'));
});

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
});

it('is linked from the page the browser loads first', function (): void {
    $head = (string) File::get(resource_path('views/app.blade.php'));

    expect($head)->toContain('rel="manifest"')
        ->toContain('prefers-color-scheme: light')
        ->toContain('prefers-color-scheme: dark');
});

/**
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
    // iOS ignores the manifest and composites `apple-touch-icon.png` onto its own background, so it must be opaque.
    $apple = public_path('apple-touch-icon.png');

    expect(File::exists($apple))->toBeTrue();

    $shape = pngShape($apple);

    $themeColor = $this->get(route('manifest'))->json('theme_color');

    expect($shape['width'])->toBe(180)
        ->and($shape['height'])->toBe(180)
        ->and($shape['corner']['alpha'])->toBe(0, 'iOS composites a transparent icon onto a colour nobody chose')
        ->and(sprintf('#%02x%02x%02x', $shape['corner']['red'], $shape['corner']['green'], $shape['corner']['blue']))
        ->toBe($themeColor);
});

it('keeps the icons a browser tab asks for', function (): void {
    expect(File::exists(public_path('favicon.ico')))->toBeTrue()
        ->and(File::exists(public_path('favicon.svg')))->toBeTrue();
});
