<?php

declare(strict_types=1);

/**
 * @return list<string>
 */
function declaredKeys(string $file): array
{
    $contents = file_get_contents(dirname(__DIR__, 2).'/'.$file);

    expect($contents)->not->toBeFalse("{$file} is missing");

    preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', (string) $contents, $matches);

    return $matches[1];
}

it('declares the same environment in both example files, in the same order', function (): void {
    expect(declaredKeys('.env.docker.example'))->toBe(declaredKeys('.env.example'));
});

it('points the docker environment at the compose services', function (): void {
    $docker = (string) file_get_contents(dirname(__DIR__, 2).'/.env.docker.example');

    expect($docker)
        ->toContain('DB_HOST=pgsql')
        ->toContain('REDIS_HOST=redis')
        ->toContain('MEILISEARCH_HOST=http://meilisearch:7700')
        ->toContain('MAIL_HOST=mailpit')
        // PHP and the browser reach Reverb at different hosts, so the client host must not derive from `REVERB_HOST`.
        ->toContain('REVERB_HOST=reverb')
        ->toContain('VITE_REVERB_HOST=localhost');
});
