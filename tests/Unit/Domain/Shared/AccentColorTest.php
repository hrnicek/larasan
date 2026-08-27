<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\ValueObjects\AccentColor;

it('accepts a palette name and knows which case it is', function (): void {
    $color = new AccentColor('teal');

    expect($color->value)->toBe('teal')
        ->and($color->paletteColor())->toBe(ProjectColor::Teal)
        ->and($color->isCustom())->toBeFalse();
});

it('accepts a hex and lower-cases it', function (): void {
    // `#AABBCC` and `#aabbcc` are one colour, not two rows that look identical.
    $color = new AccentColor('  #AaBbCc ');

    expect($color->value)->toBe('#aabbcc')
        ->and($color->isCustom())->toBeTrue()
        ->and($color->paletteColor())->toBeNull();
});

it('refuses anything that is neither', function (string $value): void {
    expect(fn () => new AccentColor($value))->toThrow(InvalidArgumentException::class)
        ->and(AccentColor::tryFrom($value))->toBeNull();
})->with([
    'a name the palette does not have' => 'burgundy',
    'three-digit shorthand' => '#abc',
    'eight digits' => '#aabbccdd',
    'no hash' => 'aabbcc',
    'not hexadecimal' => '#gggggg',
    'a function' => 'rgb(1,2,3)',
    'empty' => '',
]);

it('reads an absent colour as no colour rather than as an error', function (): void {
    expect(AccentColor::tryFrom(null))->toBeNull();
});

it('is equal to another of the same value', function (): void {
    expect(AccentColor::palette(ProjectColor::Rose)->equals(new AccentColor('rose')))->toBeTrue()
        ->and((new AccentColor('#123456'))->equals(new AccentColor('#123456')))->toBeTrue()
        ->and((new AccentColor('#123456'))->equals(new AccentColor('#123457')))->toBeFalse();
});
