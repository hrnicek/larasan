<?php

declare(strict_types=1);

namespace App\Domain\Comment\Models;

interface Commentable
{
    public function workspaceId(): string;
}
