<?php

declare(strict_types=1);

namespace App\Domain\Shared\Payloads;

use App\Domain\Account\Support\AvatarPresets;
use App\Models\User;

/**
 * A person, as every screen in this application draws one.
 *
 * The shape was written out at a dozen call sites — assignees, followers, uploaders, the faces in
 * a header, the people a task can be handed to — and this is the one place that says where a
 * face comes from: an uploaded picture, one of the shipped illustrations, or nothing, in which
 * case `UserAvatar` draws initials.
 *
 * Listed explicitly rather than serialising the model, for the reason `HandleInertiaRequests`
 * gives: a column added to `users` later must not become a public API by accident. The raw
 * avatar columns are read here and never sent; the client receives a URL.
 *
 * A query that selects narrowly selects `columns()` or `faceColumns()`. Strict Eloquent throws
 * on an attribute the query never read, so a hand-written list that forgets the avatar is a 500
 * on that screen rather than a missing picture.
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
     * The same, for a field that is allowed to be nobody — an unassigned task, a file whose
     * uploader has since been removed.
     *
     * @return array{id: int, name: string, email: string, avatar: string|null}|null
     */
    public static function fromNullable(?User $person): ?array
    {
        return $person instanceof User ? self::from($person) : null;
    }

    /**
     * Without the address, for a list that is drawn rather than read: the faces in a project's
     * header are public to everybody who may open the project, and a stack of avatars is not a
     * reason to hand out a colleague's email (ADR-0006).
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
     * What `from()` reads, qualified by `$table` for a query that joins.
     *
     * @return list<string>
     */
    public static function columns(?string $table = null): array
    {
        return self::qualify(['id', 'name', 'email', ...self::AVATAR_COLUMNS], $table);
    }

    /**
     * What `face()` reads.
     *
     * @return list<string>
     */
    public static function faceColumns(?string $table = null): array
    {
        return self::qualify(['id', 'name', ...self::AVATAR_COLUMNS], $table);
    }

    /**
     * An eager load of a relation to a person, narrowed to what `from()` reads:
     * `PersonSummary::eager('assignee')` in place of `'assignee:id,name,email'`.
     */
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
