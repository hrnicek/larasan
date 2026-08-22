<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * What a line of history says happened.
 *
 * Values are persisted and read back by the feed, so they are stable — a renamed case is a
 * migration, not an edit. Namespaced by subject so a second kind of subject can be added
 * without the values colliding.
 */
enum ActivityType: string
{
    case TaskCreated = 'task.created';
    case TaskUpdated = 'task.updated';
    case TaskCompleted = 'task.completed';
    case TaskReopened = 'task.reopened';
    case TaskAssigned = 'task.assigned';
    case TaskAttachedToProject = 'task.attached_to_project';
    case TaskDetachedFromProject = 'task.detached_from_project';
}
