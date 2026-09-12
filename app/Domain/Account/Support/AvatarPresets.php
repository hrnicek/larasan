<?php

declare(strict_types=1);

namespace App\Domain\Account\Support;

final class AvatarPresets
{
    // Mirrors the users_avatar_preset_known check constraint, so raising it needs a migration.
    public const int COUNT = 26;

    /**
     * @return list<int>
     */
    public static function all(): array
    {
        return range(1, self::COUNT);
    }

    public static function exists(int $preset): bool
    {
        return $preset >= 1 && $preset <= self::COUNT;
    }

    public static function url(int $preset): string
    {
        return asset("img/avatars/{$preset}.svg");
    }
}
