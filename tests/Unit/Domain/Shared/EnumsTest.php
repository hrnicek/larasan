<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;

/**
 * These values are persisted, so a rename is a data migration rather than a
 * refactor. The assertions exist to make that impossible to do by accident.
 */
it('pins every persisted enum value', function (string $enum, array $expected): void {
    $actual = array_map(fn ($case): string => $case->value, $enum::cases());

    expect($actual)->toBe($expected);
})->with([
    'capability' => [Capability::class, [
        'workspace.manage',
        'workspace.delete',
        'workspace.members.manage',
        'project.create',
        'project.update',
        'project.delete',
        'task.create',
        'task.update',
        'task.delete',
        'task.assign',
        'comment.create',
        'comment.delete',
        'file.upload',
    ]],
    'workspace role' => [WorkspaceRole::class, ['owner', 'admin', 'member', 'guest']],
    'membership status' => [WorkspaceMembershipStatus::class, ['invited', 'active', 'declined']],
    'project access level' => [ProjectAccessLevel::class, ['owner', 'editor', 'commenter', 'viewer']],
    'project visibility' => [ProjectVisibility::class, ['workspace', 'private']],
    'project default view' => [ProjectDefaultView::class, ['list', 'board']],
    'task priority' => [TaskPriority::class, ['low', 'medium', 'high', 'urgent']],
]);

it('grants the owner every capability', function (): void {
    expect(WorkspaceRole::Owner->capabilities())->toBe(Capability::cases());
});

it('withholds only workspace deletion from an admin', function (): void {
    expect(WorkspaceRole::Admin->allows(Capability::WorkspaceDelete))->toBeFalse()
        ->and(WorkspaceRole::Admin->allows(Capability::WorkspaceManage))->toBeTrue()
        ->and(WorkspaceRole::Admin->allows(Capability::WorkspaceMembersManage))->toBeTrue();
});

it('withholds every workspace administration capability from a member', function (): void {
    expect(WorkspaceRole::Member->allows(Capability::WorkspaceManage))->toBeFalse()
        ->and(WorkspaceRole::Member->allows(Capability::WorkspaceDelete))->toBeFalse()
        ->and(WorkspaceRole::Member->allows(Capability::WorkspaceMembersManage))->toBeFalse()
        ->and(WorkspaceRole::Member->allows(Capability::ProjectCreate))->toBeTrue()
        ->and(WorkspaceRole::Member->allows(Capability::TaskAssign))->toBeTrue();
});

it('limits a guest to commenting', function (): void {
    expect(WorkspaceRole::Guest->capabilities())->toBe([Capability::CommentCreate]);
});

it('reports ownership', function (): void {
    expect(WorkspaceRole::Owner->isOwner())->toBeTrue()
        ->and(WorkspaceRole::Admin->isOwner())->toBeFalse();
});

it('resolves project access level permissions', function (
    ProjectAccessLevel $level,
    bool $manage,
    bool $edit,
    bool $comment,
): void {
    expect($level->canManageProject())->toBe($manage)
        ->and($level->canEdit())->toBe($edit)
        ->and($level->canComment())->toBe($comment);
})->with([
    'owner' => [ProjectAccessLevel::Owner, true, true, true],
    'editor' => [ProjectAccessLevel::Editor, false, true, true],
    'commenter' => [ProjectAccessLevel::Commenter, false, false, true],
    'viewer' => [ProjectAccessLevel::Viewer, false, false, false],
]);

it('requires explicit membership only for private projects', function (): void {
    expect(ProjectVisibility::Private->requiresExplicitMembership())->toBeTrue()
        ->and(ProjectVisibility::Workspace->requiresExplicitMembership())->toBeFalse();
});

it('grants workspace access only to an active membership', function (): void {
    expect(WorkspaceMembershipStatus::Active->grantsAccess())->toBeTrue()
        ->and(WorkspaceMembershipStatus::Invited->grantsAccess())->toBeFalse()
        ->and(WorkspaceMembershipStatus::Declined->grantsAccess())->toBeFalse();
});

it('orders task priority by ascending urgency', function (): void {
    $weights = array_map(fn (TaskPriority $priority): int => $priority->weight(), TaskPriority::cases());

    expect($weights)->toBe([1, 2, 3, 4]);
});
