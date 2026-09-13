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
});

it("matches this application's host and nothing else", function (): void {
    $ours = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

    $matches = static fn (string $origin): bool => collect(allowedOrigins())
        ->contains(static fn (string $pattern): bool => Str::is($pattern, $origin));

    expect($matches($ours))->toBeTrue()
        ->and($matches('evil.example.com'))->toBeFalse()
        ->and($matches('localhost.evil.example.com'))->toBeFalse();
});

it('accepts no client events at all', function (): void {
    // Reverb rejects client events for any value other than these two.
    expect(in_array(config('reverb.apps.apps.0.accept_client_events_from'), ['all', 'members'], true))
        ->toBeFalse();
});

it('rate limits a connection, and drops one that will not stop', function (): void {
    expect(config('reverb.apps.apps.0.rate_limiting.enabled'))->toBeTrue()
        ->and(config('reverb.apps.apps.0.rate_limiting.terminate_on_limit'))->toBeTrue()
        ->and(config('reverb.apps.apps.0.rate_limiting.max_attempts'))->toBeGreaterThan(0);
});

it('bounds what one message may cost', function (): void {
    // The largest message a client sends is a subscription: a channel name and a signature.
    expect(config('reverb.apps.apps.0.max_message_size'))
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(10_000);
});
