<?php

declare(strict_types=1);

namespace App\Domain\File\Models;

/**
 * Something a file can be attached to.
 *
 * Exists for the reason `Commentable` does: a file carries its own `workspace_id`, and that
 * workspace has to be the **subject's** rather than whatever the request was scoped to. Asking
 * a plain `Model` for `workspace_id` works until somebody attaches a file to a model that has
 * none, and then writes a null into the column the tenancy rests on.
 */
interface Attachable
{
    public function workspaceId(): string;
}
