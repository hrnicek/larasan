<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectColor;
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
        'section.create',
        'section.update',
        'section.delete',
        'task.create',
        'task.update',
        'task.delete',
        'task.assign',
        'comment.create',
        'comment.delete',
        'tag.manage',
        'custom_field.manage',
        'file.upload',
        'file.delete',
    ]],
    'workspace role' => [WorkspaceRole::class, ['owner', 'admin', 'member', 'guest']],
    'membership status' => [WorkspaceMembershipStatus::class, ['invited', 'active', 'declined', 'revoked', 'expired']],
    'project access level' => [ProjectAccessLevel::class, ['owner', 'editor', 'commenter', 'viewer']],
    'project visibility' => [ProjectVisibility::class, ['workspace', 'private']],
    'project default view' => [ProjectDefaultView::class, ['list', 'board', 'calendar']],
    'project color' => [ProjectColor::class, ['slate', 'red', 'amber', 'emerald', 'teal', 'sky', 'violet', 'rose']],
    'task priority' => [TaskPriority::class, ['low', 'medium', 'high', 'urgent']],
]);

/**
 * Pinned per role rather than asserted against an expression, so adding a Capability
 * case fails here until someone decides what each role should do with it.
 */
it('pins the capabilities of every role', function (WorkspaceRole $role, array $expected): void {
    $granted = array_map(fn (Capability $capability): string => $capability->value, $role->capabilities());

    expect($granted)->toBe($expected);
})->with([
    'owner' => [WorkspaceRole::Owner, [
        'workspace.manage', 'workspace.delete', 'workspace.members.manage',
        'project.create', 'project.update', 'project.delete',
        'section.create', 'section.update', 'section.delete',
        'task.create', 'task.update', 'task.delete', 'task.assign',
        'comment.create', 'comment.delete',
        'tag.manage', 'custom_field.manage',
        'file.upload', 'file.delete',
    ]],
    'admin' => [WorkspaceRole::Admin, [
        'workspace.manage', 'workspace.members.manage',
        'project.create', 'project.update', 'project.delete',
        'section.create', 'section.update', 'section.delete',
        'task.create', 'task.update', 'task.delete', 'task.assign',
        'comment.create', 'comment.delete',
        'tag.manage', 'custom_field.manage',
        'file.upload', 'file.delete',
    ]],
    'member' => [WorkspaceRole::Member, [
        'project.create', 'project.update', 'project.delete',
        'section.create', 'section.update', 'section.delete',
        'task.create', 'task.update', 'task.delete', 'task.assign',
        'comment.create', 'comment.delete',
        'tag.manage',
        'file.upload', 'file.delete',
    ]],
    'guest' => [WorkspaceRole::Guest, ['comment.create']],
]);

it('covers every capability in the role matrix', function (): void {
    $granted = collect(WorkspaceRole::cases())
        ->flatMap(fn (WorkspaceRole $role): array => $role->capabilities())
        ->unique()
        ->values();

    expect($granted->all())->toBe(Capability::cases());
});

it('withholds workspace deletion from everyone but the owner', function (): void {
    expect(WorkspaceRole::Owner->allows(Capability::WorkspaceDelete))->toBeTrue()
        ->and(WorkspaceRole::Admin->allows(Capability::WorkspaceDelete))->toBeFalse()
        ->and(WorkspaceRole::Member->allows(Capability::WorkspaceDelete))->toBeFalse()
        ->and(WorkspaceRole::Guest->allows(Capability::WorkspaceDelete))->toBeFalse();
});

it('withholds workspace administration from a member', function (): void {
    expect(WorkspaceRole::Member->allows(Capability::WorkspaceManage))->toBeFalse()
        ->and(WorkspaceRole::Member->allows(Capability::WorkspaceMembersManage))->toBeFalse()
        ->and(WorkspaceRole::Member->allows(Capability::CustomFieldManage))->toBeFalse();
});

it('denies a guest every capability except commenting', function (Capability $capability): void {
    expect(WorkspaceRole::Guest->allows($capability))->toBe($capability === Capability::CommentCreate);
})->with(Capability::cases());

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

it('grants workspace access only to an active membership', function (WorkspaceMembershipStatus $status): void {
    expect($status->grantsAccess())->toBe($status === WorkspaceMembershipStatus::Active);
})->with(WorkspaceMembershipStatus::cases());

it('accepts an invitation only from the invited state', function (WorkspaceMembershipStatus $status): void {
    expect($status->canBeAccepted())->toBe($status === WorkspaceMembershipStatus::Invited);
})->with(WorkspaceMembershipStatus::cases());
