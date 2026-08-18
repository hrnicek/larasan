<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Workspace-wide capabilities. A capability answers "may this role ever do this kind
 * of thing in this workspace"; whether it may do it to a *specific* project or task is
 * decided separately by ProjectAccessLevel (ADR-0006).
 *
 * Values are persisted in authorization assertions and logs, so they are stable.
 */
enum Capability: string
{
    case WorkspaceManage = 'workspace.manage';
    case WorkspaceDelete = 'workspace.delete';
    case WorkspaceMembersManage = 'workspace.members.manage';

    case ProjectCreate = 'project.create';
    case ProjectUpdate = 'project.update';
    case ProjectDelete = 'project.delete';

    case TaskCreate = 'task.create';
    case TaskUpdate = 'task.update';
    case TaskDelete = 'task.delete';
    case TaskAssign = 'task.assign';

    case CommentCreate = 'comment.create';
    case CommentDelete = 'comment.delete';

    case FileUpload = 'file.upload';
}
