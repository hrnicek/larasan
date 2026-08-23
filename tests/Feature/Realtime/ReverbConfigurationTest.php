<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/**
 * @return list<string>
 */
function allowedOrigins(): array
{
    return config('reverb.apps.apps.0.allowed_origins');
}

it('does not invite the whole internet to open a socket', function (): void {
    expect(allowedOrigins())
        ->not->toContain('*')
        ->not->toBeEmpty();
})->with([
    'an unauthenticated socket is the cheapest thing in this application to open',
]);

it('matches this applications host and nothing else', function (): void {
    $ours = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

    $matches = static fn (string $origin): bool => collect(allowedOrigins())
        ->contains(static fn (string $pattern): bool => Str::is($pattern, $origin));

    expect($matches($ours))->toBeTrue()
        ->and($matches('evil.example.com'))->toBeFalse()
        ->and($matches('localhost.evil.example.com'))->toBeFalse();
})->with([
    'asserted with the matcher Reverb itself uses, so a pattern that looks restrictive and is not
    fails here rather than in production',
]);

it('accepts no client events at all', function (): void {
    // Reverb rejects client events for any value other than these two.
    expect(in_array(config('reverb.apps.apps.0.accept_client_events_from'), ['all', 'members'], true))
        ->toBeFalse();
})->with([
    'every change here goes through an endpoint that authorizes it and comes back as a broadcast
    the server sent; a client that could message a channel directly would skip all of that',
]);

it('rate limits a connection, and drops one that will not stop', function (): void {
    expect(config('reverb.apps.apps.0.rate_limiting.enabled'))->toBeTrue()
        ->and(config('reverb.apps.apps.0.rate_limiting.terminate_on_limit'))->toBeTrue()
        ->and(config('reverb.apps.apps.0.rate_limiting.max_attempts'))->toBeGreaterThan(0);
})->with([
    'our own clients send a handful of messages when a screen mounts and then listen, so a
    connection making sixty in a minute is not one of ours',
]);

it('bounds what one message may cost', function (): void {
    expect(config('reverb.apps.apps.0.max_message_size'))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(10_000);
})->with([
    'the largest message a client sends here is a subscription — a channel name and a signature',
]);
