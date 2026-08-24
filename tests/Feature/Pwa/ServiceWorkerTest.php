<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * The worker's policy is JavaScript, so asserting it by reading the file for phrases would prove
 * only that the phrases are there. These tests run `isCacheable` through Node against a table of
 * addresses instead — the same Node the build already requires.
 */

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
    // Every one of these is somebody's data or a document that carries it. A cached page is one
    // account's data served to whoever opens the browser next on a shared device, and that is the
    // whole reason this worker is an allow-list.
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
        // Unhashed: an address whose bytes can change is an address cache-first must not hold.
        '/build/assets/app.js',
        '/favicon.ico',
        '/icon-192.png',
        // Same shape as an asset, one directory up — the allow-list is anchored for this reason.
        '/uploads/build/assets/app-BpVLLJN2.js',
    ];

    expect(cacheDecisions($forbidden))->each->toBeFalse();
})->with([
    'a block-list is a list of the responses somebody thought of; this asserts the allow-list
    refuses the ones nobody did',
]);

it('deletes the caches it no longer uses', function (): void {
    // Without this an old version's entries survive every deployment, and the storage a browser
    // grants is finite: the eviction it eventually performs takes the current cache too.
    $worker = File::get(public_path('sw.js'));

    expect($worker)->toContain("addEventListener('activate'")
        ->toContain('caches.delete');
});

it('is not registered in development', function (): void {
    // A cached asset in development is a debugging session nobody enjoys, and Vite already serves
    // from memory. `import.meta.env.PROD` is the build-time constant, so the branch is removed
    // from the development bundle rather than merely skipped at runtime.
    $entry = File::get(resource_path('js/app.ts'));

    expect($entry)->toContain('import.meta.env.PROD')
        ->toContain("navigator.serviceWorker.register('/sw.js')");
});
