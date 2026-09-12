<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum ProjectDefaultView: string
{
    case List = 'list';
    case Board = 'board';
    case Calendar = 'calendar';
}
