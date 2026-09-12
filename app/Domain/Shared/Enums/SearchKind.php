<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum SearchKind: string
{
    case Tasks = 'tasks';
    case Projects = 'projects';
    case People = 'people';
    case Messages = 'messages';
    case Pages = 'pages';
}
