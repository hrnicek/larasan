<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Project\Policies\ProjectPolicy;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Gate;

it('is the policy the gate resolves for a project', function (): void {
    expect(Gate::getPolicyFor(Project::class))->toBeInstanceOf(ProjectPolicy::class);
});

it('denies every ability to someone outside the workspace', function (string $ability): void {
    $project = Project::factory()->create();
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    expect(Gate::forUser($outsider)->allows($ability, $project))->toBeFalse();
})->with(['view', 'update', 'archive', 'manageMembers', 'delete', 'createTask', 'comment']);

it('denies every ability while the workspace membership is not active', function (string $ability): void {
    [$project, $actor] = projectFor(
        WorkspaceRole::Owner,
        ProjectAccessLevel::Owner,
        status: WorkspaceMembershipStatus::Revoked,
    );

    expect(Gate::forUser($actor)->allows($ability, $project))->toBeFalse();
})->with(['view', 'update', 'archive', 'manageMembers', 'delete', 'createTask', 'comment']);

it('lets a workspace member read a workspace-visible project without a membership row', function (): void {
    [$project, $member] = projectFor(WorkspaceRole::Member);

    expect(Gate::forUser($member)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $project))->toBeFalse()
        ->and(Gate::forUser($member)->allows('createTask', $project))->toBeFalse();
});

it('hides a private project from a workspace member who is not in it', function (): void {
    [$project, $member] = projectFor(WorkspaceRole::Member, visibility: ProjectVisibility::Private);

    expect(Gate::forUser($member)->allows('view', $project))->toBeFalse();
});

it('hides a private project even from a workspace owner', function (): void {
    [$project, $owner] = projectFor(WorkspaceRole::Owner, visibility: ProjectVisibility::Private);

    expect(Gate::forUser($owner)->allows('view', $project))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $project))->toBeFalse();
});

it('never grants a guest access by visibility alone', function (): void {
    [$project, $guest] = projectFor(WorkspaceRole::Guest);

    expect(Gate::forUser($guest)->allows('view', $project))->toBeFalse();
});

it('lets a guest into the project they were explicitly given', function (): void {
    [$project, $guest] = projectFor(WorkspaceRole::Guest, ProjectAccessLevel::Commenter);

    expect(Gate::forUser($guest)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($guest)->allows('comment', $project))->toBeTrue()
        ->and(Gate::forUser($guest)->allows('createTask', $project))->toBeFalse()
        ->and(Gate::forUser($guest)->allows('update', $project))->toBeFalse();
});

it('needs both halves before it lets anyone act', function (
    WorkspaceRole $role,
    ProjectAccessLevel $access,
    string $ability,
    bool $allowed,
): void {
    [$project, $actor] = projectFor($role, $access);

    expect(Gate::forUser($actor)->allows($ability, $project))->toBe($allowed);
})->with([
    'member editing tasks in a project they edit' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'createTask', true],
    'member editing tasks in a project they only view' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'createTask', false],
    'guest with edit access still lacks the workspace capability' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'createTask', false],
    'member managing a project they own' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'update', true],
    'member managing a project they only edit' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'update', false],
    'guest owning a project cannot manage it' => [WorkspaceRole::Guest, ProjectAccessLevel::Owner, 'update', false],
    'commenter may comment' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'comment', true],
    'viewer may not comment' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'comment', false],
    'owner may delete' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'delete', true],
    'editor may not delete' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'delete', false],
]);

it('does not carry access from one project into another', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $mine = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $theirs = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);

    ProjectMembership::factory()->in($mine)->forUser($actor)->withAccess(ProjectAccessLevel::Owner)->create();

    expect(Gate::forUser($actor)->allows('update', $mine))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('view', $theirs))->toBeFalse();
});
