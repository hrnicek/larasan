<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum ProjectView: string
{
    case List = 'list';
    case Board = 'board';
    case Calendar = 'calendar';
    case Files = 'files';
    case Pages = 'pages';

    public static function fromDefault(ProjectDefaultView $default): self
    {
        return self::from($default->value);
    }
}
