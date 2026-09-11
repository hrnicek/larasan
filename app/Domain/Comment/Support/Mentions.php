<?php

declare(strict_types=1);

namespace App\Domain\Comment\Support;

/**
 * How a comment names a person: `@[Jana Nováková](user:42)`.
 *
 * The id is what the mention means; the name is only how it read when it was written. The thread
 * redraws it with whatever the person is called now, and falls back to the written name only for
 * somebody it can no longer place.
 *
 * Plain text rather than markup, because a comment body is plain text — drawn with `{{ }}`, never
 * `v-html` — and a token that reads as `@Name` once its brackets go keeps the search index and an
 * excerpt legible without a lookup.
 */
final class Mentions
{
    /** Enough to pull a team into a thread, few enough that a comment cannot page a workspace. */
    public const LIMIT = 20;

    private const PATTERN = '/@\[([^\[\]\r\n]{1,120})\]\(user:(\d{1,18})\)/u';

    /**
     * Everybody named, once each, in the order first named.
     *
     * @return list<int>
     */
    public static function idsIn(string $body): array
    {
        preg_match_all(self::PATTERN, $body, $matches);

        return array_values(array_unique(array_map(intval(...), $matches[2])));
    }

    /**
     * Every token rewritten with the name `$names` holds for its id. An id missing from `$names`
     * keeps the name it was written with.
     *
     * @param  array<int, string>  $names
     */
    public static function withNames(string $body, array $names): string
    {
        return (string) preg_replace_callback(self::PATTERN, function (array $match) use ($names): string {
            $id = (int) $match[2];
            $name = array_key_exists($id, $names) ? self::tokenName($names[$id]) : $match[1];

            return '@['.$name.'](user:'.$id.')';
        }, $body);
    }

    public static function toPlainText(string $body): string
    {
        return (string) preg_replace(self::PATTERN, '@$1', $body);
    }

    /**
     * A name as a token can carry it. A bracket would end the token early and a line break would
     * split it, so both become spaces.
     */
    private static function tokenName(string $name): string
    {
        $name = trim((string) preg_replace('/[\[\]\r\n]+/u', ' ', $name));

        return $name === '' ? 'someone' : mb_substr($name, 0, 120);
    }
}
