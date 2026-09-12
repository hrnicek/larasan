<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Each case needs a static entry in resources/js/lib/accentColor.ts, since Tailwind cannot see
 * interpolated class names.
 */
enum ProjectColor: string
{
    case Slate = 'slate';
    case Red = 'red';
    case Amber = 'amber';
    case Emerald = 'emerald';
    case Teal = 'teal';
    case Sky = 'sky';
    case Violet = 'violet';
    case Rose = 'rose';
}
