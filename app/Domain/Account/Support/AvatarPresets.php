<?php

declare(strict_types=1);

namespace App\Domain\Account\Support;

/**
 * The faces somebody can pick instead of uploading one: the illustrations in
 * `public/img/avatars`, numbered from one.
 *
 * Static files rather than stored objects, because they are the application's own artwork and
 * nobody's personal data — the reason an uploaded picture goes through an authorized controller
 * (ADR-0007) does not apply to a drawing every installation ships. `users_avatar_preset_known`
 * holds the same bound, so adding an illustration is a file, this number and a migration.
 */
final class AvatarPresets
{
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
