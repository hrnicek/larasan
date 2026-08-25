<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * What a URL may ask a project to show.
 *
 * A superset of `ProjectDefaultView` rather than the same enum: List, Board and Calendar are
 * three arrangements of the project's tasks and any of them is a reasonable thing for a project
 * to open on, while Files is a view of what hangs off those tasks. Keeping the two apart is what
 * lets `?view=files` be a link without `projects.default_view` — and the check constraint behind
 * it — growing a value that means "this project opens on its attachments".
 *
 * Values appear in URLs, so they are stable.
 */
enum ProjectView: string
{
    case List = 'list';
    case Board = 'board';
    case Calendar = 'calendar';
    case Files = 'files';

    public static function fromDefault(ProjectDefaultView $default): self
    {
        return self::from($default->value);
    }
}
