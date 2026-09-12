<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

interface WorkspaceNotification
{
    public function workspaceId(): string;
}
