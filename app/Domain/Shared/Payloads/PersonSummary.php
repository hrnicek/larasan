<?php

declare(strict_types=1);

namespace App\Domain\Shared\Payloads;

use App\Domain\Account\Support\AvatarPresets;
use App\Models\User;

/**
 * Narrow queries must select columns() or faceColumns(): strict Eloquent throws on an avatar
 * attribute the query never read.
 */
final readonly class PersonSummary
{
    private const array AVATAR_COLUMNS = ['avatar_preset', 'avatar_path'];

    /**
     * @return array{id: int, name: string, email: string, avatar: string|null}
     */
    public static function from(User $person): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'email' => $person->email,
            'avatar' => self::avatar($person),
        ];
    }

    /**
     * @return array{id: int, name: string, email: string, avatar: string|null}|null
     */
    public static function fromNullable(?User $person): ?array
    {
        return $person instanceof User ? self::from($person) : null;
    }

    /**
     * Omits the email because these faces are shown to everyone who can open the project.
     * See ADR-0006.
     *
     * @return array{id: int, name: string, avatar: string|null}
     */
    public static function face(User $person): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'avatar' => self::avatar($person),
        ];
    }

    /**
     * @return list<string>
     */
    public static function columns(?string $table = null): array
    {
        return self::qualify(['id', 'name', 'email', ...self::AVATAR_COLUMNS], $table);
    }

    /**
     * @return list<string>
     */
    public static function faceColumns(?string $table = null): array
    {
        return self::qualify(['id', 'name', ...self::AVATAR_COLUMNS], $table);
    }

    public static function eager(string $relation): string
    {
        return $relation.':'.implode(',', self::columns());
    }

    private static function avatar(User $person): ?string
    {
        if ($person->avatar_path !== null) {
            return route('users.avatar', [
                'user' => $person->id,
                'v' => pathinfo($person->avatar_path, PATHINFO_FILENAME),
            ]);
        }

        return $person->avatar_preset === null ? null : AvatarPresets::url($person->avatar_preset);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function qualify(array $columns, ?string $table): array
    {
        return $table === null
            ? $columns
            : array_map(fn (string $column): string => "{$table}.{$column}", $columns);
    }
}
