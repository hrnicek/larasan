<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum ProjectFileSort: string
{
    case Name = 'name';
    case Size = 'size';
    case Added = 'added';

    /** Columns are fixed here so client input never reaches orderBy(). */
    public function column(): string
    {
        return match ($this) {
            self::Name => 'files.original_name',
            self::Size => 'files.size',
            self::Added => 'attachments.created_at',
        };
    }

    public function defaultsToDescending(): bool
    {
        return $this !== self::Name;
    }
}
