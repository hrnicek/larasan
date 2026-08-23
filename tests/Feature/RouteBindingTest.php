<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * The bindings in `RouteBindingServiceProvider` are load-bearing security: each resolves its
 * model inside the workspace the actor is currently in, so another tenant's row is a 404 rather
 * than a 403 — and `inbox.read` has no authorization of its own beyond the binding.
 *
 * `php artisan route:cache`, which every production deployment runs, never reads the route files.
 * A binding declared in one therefore stops existing in production and Laravel falls back to
 * implicit binding by primary key. Measured before the move (TASK-180-001): 79 tests failed with
 * routes cached, two of them because one account could mark another's notification read and reach
 * another workspace's custom field.
 */
it('declares no binding where route caching would lose it', function (): void {
    $offenders = collect(File::files(base_path('routes')))
        ->filter(fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), 'Route::bind('))
        ->map(fn (SplFileInfo $file): string => $file->getFilename())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
})->with([
    'a route file is not read when the route table is cached, and a binding that silently stops
    running takes the tenant check with it',
]);

it('registers every parameter the routes bind', function (string $parameter): void {
    expect(Route::getBindingCallback($parameter))->not->toBeNull();
})->with([
    'project', 'task', 'section', 'placement', 'comment', 'attachment', 'tag', 'field', 'notification',
]);
