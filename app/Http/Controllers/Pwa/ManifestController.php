<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest, generated rather than kept as a file in `public/`.
 *
 * A static manifest would carry a second copy of the application's name, and the first time
 * `APP_NAME` changed an installed app would keep showing the old one — the kind of drift nobody
 * looks for because nobody remembers the file exists.
 */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json([
                'name' => config('app.name'),
                'short_name' => config('app.name'),
                /*
                 * The dashboard, which redirects to login when nobody is signed in. An installed
                 * app that opens on a 404 is one nobody opens twice.
                 */
                'start_url' => '/dashboard',
                'scope' => '/',
                'display' => 'standalone',
                /*
                 * The light surface, `--background` in `resources/css/app.css`. A manifest carries
                 * one colour; the pair that follows the reader's theme is in the root template,
                 * where `prefers-color-scheme` can express it.
                 */
                'background_color' => '#ffffff',
                'theme_color' => '#ffffff',
                'icons' => [
                    ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                    ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                    ['src' => '/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ],
            ], options: JSON_UNESCAPED_SLASHES)
            ->header('Content-Type', 'application/manifest+json');
    }
}
