<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json([
                'name' => config('app.name'),
                'short_name' => config('app.name'),
                'start_url' => '/dashboard',
                'scope' => '/',
                'display' => 'standalone',
                // One value for both themes; the theme-aware pair lives in the root template. See ADR-0014.
                'background_color' => '#ffffff',
                'theme_color' => '#16161d',
                'icons' => [
                    ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                    ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                    ['src' => '/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ],
            ], options: JSON_UNESCAPED_SLASHES)
            ->header('Content-Type', 'application/manifest+json');
    }
}
