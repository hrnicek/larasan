<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * ADR-0008: private channels only, each authorized against the same membership rules the
 * HTTP layer uses. A channel is a second door into the same data, so nothing here decides
 * access on its own — it asks the model that already answers the question everywhere else,
 * and a subscription is refused wherever a request would be.
 *
 * These are the names ADR-0008 fixed. Echo prefixes `private-` on the wire.
 */

/**
 * Workspace-wide events, including tasks that sit in no project at all — which is why a
 * guest is refused the channel rather than merely being sent nothing. A guest reaches the
 * projects they were given and nothing else, so what the channel carries decides who may
 * subscribe, not the name of the policy that happens to share its subject.
 */
Broadcast::channel('workspace.{workspace}', function (User $user, Workspace $workspace): bool {
    $membership = $workspace->membershipFor($user);

    return $membership?->status->grantsAccess() === true && ! $membership->role->isGuest();
});

/**
 * The same question `ProjectPolicy::view()` asks, asked of the same method: an active
 * workspace membership, plus either a project grant or a workspace-visible project the
 * actor is not a guest of.
 */
Broadcast::channel('project.{project}', function (User $user, Project $project): bool {
    return $project->isVisibleTo($user);
});

/**
 * ADR-0008 names this `private-user.{user}` while Laravel's notification broadcasting
 * targets `App.Models.User.{id}` by convention. Reconciled in one direction:
 * `User::receivesBroadcastNotificationsOn()` returns this name, so the application has one
 * user channel instead of two half-working ones (TASK-170-002, asserted by TASK-170-009).
 *
 * Compared as a string and never bound as a model: `users.id` is a bigint, and asking
 * PostgreSQL for a non-numeric id is a 500 rather than a refusal.
 */
Broadcast::channel('user.{userId}', function (User $user, string $userId): bool {
    return (string) $user->id === $userId;
});
