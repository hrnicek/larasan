<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum Capability: string
{
    case WorkspaceManage = 'workspace.manage';
    case WorkspaceDelete = 'workspace.delete';
    case WorkspaceMembersManage = 'workspace.members.manage';

    case ProjectCreate = 'project.create';
    case ProjectUpdate = 'project.update';
    case ProjectDelete = 'project.delete';

    case SectionCreate = 'section.create';
    case SectionUpdate = 'section.update';
    case SectionDelete = 'section.delete';

    case PageCreate = 'page.create';
    case PageUpdate = 'page.update';
    case PageDelete = 'page.delete';

    case TaskCreate = 'task.create';
    case TaskUpdate = 'task.update';
    case TaskDelete = 'task.delete';
    case TaskAssign = 'task.assign';

    case CommentCreate = 'comment.create';
    case CommentDelete = 'comment.delete';

    case TagManage = 'tag.manage';
    case CustomFieldManage = 'custom_field.manage';

    case FileUpload = 'file.upload';
    case FileDelete = 'file.delete';
}
