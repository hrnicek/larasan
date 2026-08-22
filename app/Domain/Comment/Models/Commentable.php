<?php

declare(strict_types=1);

namespace App\Domain\Comment\Models;

/**
 * Something a comment can hang from.
 *
 * The interface exists for one reason: a comment carries its own `workspace_id`, and that
 * workspace must be the **subject's** rather than whatever the request was scoped to. Asking a
 * plain `Model` for `workspace_id` would work until somebody made a comment on a model that
 * has no workspace, and would then write a null into a column the whole tenancy rests on.
 */
interface Commentable
{
    public function workspaceId(): string;
}
