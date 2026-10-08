<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

// Bindings scope models to the current workspace, and `route:cache` never reads route files, so they live in a provider.
it('declares no binding where route caching would lose it', function (): void {
    $offenders = collect(File::files(base_path('routes')))
        ->filter(fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), 'Route::bind('))
        ->map(fn (SplFileInfo $file): string => $file->getFilename())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('registers every parameter the routes bind', function (string $parameter): void {
    expect(Route::getBindingCallback($parameter))->not->toBeNull();
})->with([
    'project', 'task', 'section', 'page', 'placement', 'comment', 'attachment', 'tag', 'field', 'notification',
]);
