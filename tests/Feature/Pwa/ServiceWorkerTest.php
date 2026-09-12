<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * @param  list<string>  $paths
 * @return array<string, bool>
 */
function cacheDecisions(array $paths): array
{
    $script = <<<'JS'
        const fs = require('fs');
        const isCacheable = new Function('self', fs.readFileSync(process.argv[1], 'utf8') + '; return isCacheable;')({
            addEventListener() {},
            location: { origin: 'https://example.test' },
        });

        const decisions = {};

        for (const path of JSON.parse(process.argv[2])) {
            decisions[path] = isCacheable(path);
        }

        process.stdout.write(JSON.stringify(decisions));
        JS;

    $process = new Process(['node', '-e', $script, '--', public_path('sw.js'), json_encode($paths, JSON_THROW_ON_ERROR)], base_path());
    $process->mustRun();

    /** @var array<string, bool> $decisions */
    $decisions = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

    return $decisions;
}

it('caches every address the build actually emits', function (): void {
    $manifest = public_path('build/manifest.json');

    expect(File::exists($manifest))->toBeTrue('run `npm run build` first — this asserts against real output');

    /** @var array<string, array{file: string, css?: list<string>, assets?: list<string>}> $entries */
    $entries = json_decode(File::get($manifest), true, 512, JSON_THROW_ON_ERROR);

    $emitted = [];

    foreach ($entries as $entry) {
        foreach ([[$entry['file']], $entry['css'] ?? [], $entry['assets'] ?? []] as $group) {
            foreach ($group as $file) {
                $emitted[] = '/build/'.$file;
            }
        }
    }

    $emitted = array_values(array_unique($emitted));

    expect($emitted)->not->toBeEmpty();
    expect(cacheDecisions($emitted))->each->toBeTrue();
})->with([
    'the allow-list has to match what Vite emits, or the worker is a cache that never hits',
]);

it('never caches a page, a payload or anything a person is signed in to', function (): void {
    // A cached authenticated page would be served to the next user of a shared device.
    $forbidden = [
        '/',
        '/dashboard',
        '/tasks/0192f3c7-0000-7000-8000-000000000000',
        '/projects/0192f3c7-0000-7000-8000-000000000000',
        '/inbox',
        '/my-tasks',
        '/search?term=salary',
        '/settings/workspace',
        '/manifest.webmanifest',
        '/build/manifest.json',
        // Unhashed, so its contents can change under the same address.
        '/build/assets/app.js',
        '/favicon.ico',
        '/icon-192.png',
        // Asset-shaped path outside /build; the allow-list must be anchored.
        '/uploads/build/assets/app-BpVLLJN2.js',
        // Precached only by the explicit `cache.add` on install, never from a response.
        '/offline.html',
    ];

    expect(cacheDecisions($forbidden))->each->toBeFalse();
})->with([
    'a block-list is a list of the responses somebody thought of; this asserts the allow-list
    refuses the ones nobody did',
]);

it('answers a failed navigation with the shell rather than the browser error page', function (): void {
    $worker = File::get(public_path('sw.js'));

    expect($worker)->toContain("const OFFLINE = '/offline.html'")
        ->toContain('cache.add(OFFLINE)')
        ->toContain("request.mode === 'navigate'")
        ->toContain('caches.match(OFFLINE)');

    expect(File::exists(public_path('offline.html')))->toBeTrue();
})->with([
    'a fallback fetched at the moment it is needed is a fallback that never arrives',
]);

it('holds a fallback with nothing in it that belongs to anybody', function (): void {
    $fallback = File::get(public_path('offline.html'));

    expect($fallback)
        ->not->toContain('{{')
        ->not->toContain('@vite')
        ->not->toContain('csrf')
        ->not->toContain('data-page')
        // Must be self-contained: external assets cannot be fetched while offline.
        ->not->toContain('<link rel="stylesheet"')
        ->not->toContain('<script src');

    expect($fallback)->toContain('No connection')
        ->toContain("addEventListener('online'");
})->with([
    'the cached document has to be one nobody can be identified from, or the worker is caching a
    page after all',
]);

it('deletes the caches it no longer uses', function (): void {
    $worker = File::get(public_path('sw.js'));

    expect($worker)->toContain("addEventListener('activate'")
        ->toContain('caches.delete');
});

it('bumps its cache version when the one unhashed file it holds can change', function (): void {
    $worker = File::get(public_path('sw.js'));

    expect($worker)->toMatch("/const VERSION = 'v\d+'/")
        ->toContain('const CACHE = `shell-${VERSION}`');
});

it('is not registered in development', function (): void {
    $entry = File::get(resource_path('js/app.ts'));

    expect($entry)->toContain('import.meta.env.PROD')
        ->toContain("navigator.serviceWorker.register('/sw.js')");
});
