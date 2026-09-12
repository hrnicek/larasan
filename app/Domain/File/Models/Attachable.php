<?php

declare(strict_types=1);

namespace App\Domain\File\Models;

interface Attachable
{
    public function workspaceId(): string;
}
