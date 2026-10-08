<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum ActivityType: string
{
    case TaskCreated = 'task.created';
    case TaskUpdated = 'task.updated';
    case TaskCompleted = 'task.completed';
    case TaskReopened = 'task.reopened';
    case TaskAssigned = 'task.assigned';
    case TaskCollaboratorAdded = 'task.collaborator_added';
    case TaskCollaboratorRemoved = 'task.collaborator_removed';
    case TaskAttachedToProject = 'task.attached_to_project';
    case TaskDetachedFromProject = 'task.detached_from_project';
}
