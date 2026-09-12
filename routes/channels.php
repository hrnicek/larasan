<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Private channels, authorized by the same model rules as HTTP requests. See ADR-0008.

// Guests are refused: the channel carries tasks outside the projects they were given.
Broadcast::channel('workspace.{workspace}', function (User $user, Workspace $workspace): bool {
    $membership = $workspace->membershipFor($user);

    return $membership?->status->grantsAccess() === true && ! $membership->role->isGuest();
});

Broadcast::channel('project.{project}', function (User $user, Project $project): bool {
    return $project->isVisibleTo($user);
});

// Compared as a string, not bound: a non-numeric id against bigint `users.id` is a Postgres error.
Broadcast::channel('user.{userId}', function (User $user, string $userId): bool {
    return (string) $user->id === $userId;
});
