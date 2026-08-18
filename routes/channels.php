<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Laravel's notification broadcasting targets App.Models.User.{id} by convention, while
 * ADR-0008 specifies private-user.{user}. Phase 170 reconciles the two deliberately
 * (TASK-170-002, asserted by TASK-170-009); until then this is the framework default and nothing depends on it.
 */
Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return $user->id === (int) $id;
});
