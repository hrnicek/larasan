<?php

declare(strict_types=1);

namespace App\Domain\Shared\Payloads;

use App\Models\User;

/**
 * A person, as every screen in this application draws one.
 *
 * The shape was written out at a dozen call sites — assignees, followers, uploaders, the faces in
 * a header, the people a task can be handed to — and each one carried its own `'avatar' => null`.
 * That null is a promise the application has not kept yet: `UserAvatar` draws initials until
 * somebody uploads a face, and the day one can be uploaded is the day twelve payloads have to
 * agree about where it comes from.
 *
 * Listed explicitly rather than serialising the model, for the reason `HandleInertiaRequests`
 * gives: a column added to `users` later must not become a public API by accident.
 */
final readonly class PersonSummary
{
    /**
     * @return array{id: int, name: string, email: string, avatar: null}
     */
    public static function from(User $person): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'email' => $person->email,
            'avatar' => null,
        ];
    }

    /**
     * The same, for a field that is allowed to be nobody — an unassigned task, a file whose
     * uploader has since been removed.
     *
     * @return array{id: int, name: string, email: string, avatar: null}|null
     */
    public static function fromNullable(?User $person): ?array
    {
        return $person instanceof User ? self::from($person) : null;
    }

    /**
     * Without the address, for a list that is drawn rather than read: the faces in a project's
     * header are public to everybody who may open the project, and a stack of avatars is not a
     * reason to hand out a colleague's email (ADR-0006). Reads only the two columns those
     * queries select, so a narrow `get(['users.id', 'users.name'])` stays narrow.
     *
     * @return array{id: int, name: string, avatar: null}
     */
    public static function face(User $person): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'avatar' => null,
        ];
    }
}
