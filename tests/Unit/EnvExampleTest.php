<?php

declare(strict_types=1);

/**
 * The keys `.env.example` and `.env.docker.example` declare, in the order they appear.
 *
 * @return list<string>
 */
function declaredKeys(string $file): array
{
    $contents = file_get_contents(dirname(__DIR__, 2).'/'.$file);

    expect($contents)->not->toBeFalse("{$file} is missing");

    preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', (string) $contents, $matches);

    return $matches[1];
}

/*
 * Two environment files, one for the host and one for the containers in `compose.yaml`. They
 * differ only in the hostnames a service is reached at, so a key added to one and forgotten in the
 * other is a variable a contributor has in one runtime and not the other — which is exactly the
 * kind of difference that is found much later, in the runtime nobody used that day.
 */
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
        // What PHP dials to reach the websocket server, and what the browser dials. They are not
        // the same host, and `.env.example` derives the second from the first — so a copy that
        // kept that derivation would hand the client a name only Docker can resolve.
        ->toContain('REVERB_HOST=reverb')
        ->toContain('VITE_REVERB_HOST=localhost');
});
