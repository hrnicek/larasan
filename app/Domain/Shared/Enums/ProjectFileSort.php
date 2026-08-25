<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * The three things people look for a file by.
 *
 * Not the other columns, and deliberately: ordering by uploader or by kind sorts a table into
 * groups without labelling them, which is what grouping is for and this is not. Ordering by the
 * task a file hangs from would sort by a title the reader is not reading.
 *
 * Values appear in URLs — an ordering is a link somebody sends — so they are stable, and the
 * column each one maps to is named here rather than in the query, because a client-supplied
 * string must never reach an `orderBy`.
 */
enum ProjectFileSort: string
{
    case Name = 'name';
    case Size = 'size';
    case Added = 'added';

    public function column(): string
    {
        return match ($this) {
            self::Name => 'files.original_name',
            self::Size => 'files.size',
            self::Added => 'attachments.created_at',
        };
    }

    /**
     * Which way round the column reads when nobody has said. Newest first for a moment, because
     * the recent one is the one being looked for; A to Z for a name, because that is what a name
     * is for.
     */
    public function defaultsToDescending(): bool
    {
        return $this !== self::Name;
    }
}
