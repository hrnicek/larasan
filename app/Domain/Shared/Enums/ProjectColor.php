<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * The accent palette from `docs/ui/design-system.md`. The column stores the *name*: a
 * user-chosen hex is unreadable in one of the two themes sooner or later, and a name can
 * be re-tuned globally without a data migration. The name → Tailwind utility mapping is a
 * static record in `resources/js/lib/accentColor.ts`, because interpolated class names are
 * stripped by the JIT scanner.
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
