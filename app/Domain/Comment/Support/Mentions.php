<?php

declare(strict_types=1);

namespace App\Domain\Comment\Support;

/** Mention token: `@[Name](user:42)`. The id is authoritative; the name is display only. */
final class Mentions
{
    public const LIMIT = 20;

    private const PATTERN = '/@\[([^\[\]\r\n]{1,120})\]\(user:(\d{1,18})\)/u';

    /**
     * @return list<int>
     */
    public static function idsIn(string $body): array
    {
        preg_match_all(self::PATTERN, $body, $matches);

        return array_values(array_unique(array_map(intval(...), $matches[2])));
    }

    /**
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

    private static function tokenName(string $name): string
    {
        $name = trim((string) preg_replace('/[\[\]\r\n]+/u', ' ', $name));

        return $name === '' ? 'someone' : mb_substr($name, 0, 120);
    }
}
