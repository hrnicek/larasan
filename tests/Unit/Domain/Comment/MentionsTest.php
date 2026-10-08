<?php

declare(strict_types=1);

use App\Domain\Comment\Support\Mentions;

it('finds everybody named, once each, in the order first named', function (): void {
    $body = '@[Ana](user:3) and @[Ben](user:1), then @[Ana again](user:3)';

    expect(Mentions::idsIn($body))->toBe([3, 1]);
});

it('finds nothing in text that only looks like a mention', function (string $body): void {
    expect(Mentions::idsIn($body))->toBe([]);
})->with([
    'a bare at-sign' => ['@Ana said so'],
    'an address' => ['write to ana@example.com'],
    'no name' => ['@[](user:3)'],
    'no id' => ['@[Ana](user:)'],
    'not a user' => ['@[Ana](task:3)'],
    'a name across two lines' => ["@[An\na](user:3)"],
]);

it('rewrites the names it knows and keeps the ones it does not', function (): void {
    $body = 'Ask @[Old name](user:3) or @[Ben](user:9)';

    expect(Mentions::withNames($body, [3 => 'Jana Nováková']))
        ->toBe('Ask @[Jana Nováková](user:3) or @[Ben](user:9)');
});

it('writes a name the token can carry', function (): void {
    // A bracket would end the token early and a line break would split it.
    expect(Mentions::withNames('@[x](user:5)', [5 => "Ja]n\nNo[vák"]))->toBe('@[Ja n No vák](user:5)')
        ->and(Mentions::idsIn(Mentions::withNames('@[x](user:5)', [5 => "Ja]n\nNo[vák"])))->toBe([5]);
});

it('reads as plain text with the name after the at-sign', function (): void {
    expect(Mentions::toPlainText('Thanks @[Jana Nováková](user:3)!'))->toBe('Thanks @Jana Nováková!');
});
