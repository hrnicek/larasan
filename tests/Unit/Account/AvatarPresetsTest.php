<?php

use App\Domain\Account\Support\AvatarPresets;

test('every illustration on offer is a file the application ships', function () {
    foreach (AvatarPresets::all() as $preset) {
        expect(dirname(__DIR__, 3)."/public/img/avatars/{$preset}.svg")->toBeFile();
    }
});

test('the catalogue is numbered from one without gaps', function () {
    expect(AvatarPresets::all())->toBe(range(1, AvatarPresets::COUNT))
        ->and(AvatarPresets::exists(0))->toBeFalse()
        ->and(AvatarPresets::exists(1))->toBeTrue()
        ->and(AvatarPresets::exists(AvatarPresets::COUNT))->toBeTrue()
        ->and(AvatarPresets::exists(AvatarPresets::COUNT + 1))->toBeFalse();
});
