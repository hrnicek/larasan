<?php

declare(strict_types=1);

use App\Domain\Page\Models\Page;
use App\Domain\Page\Policies\PagePolicy;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * @return array{Page, User, Project}
 */
function pageFor(
    WorkspaceRole $role,
    ?ProjectAccessLevel $access = null,
    ProjectVisibility $visibility = ProjectVisibility::Workspace,
): array {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create(['visibility' => $visibility]);

    if ($access !== null) {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();
    }

    return [Page::factory()->in($project)->create(), $actor, $project];
}

it('is the policy the gate resolves for a page', function (): void {
    expect(Gate::getPolicyFor(Page::class))->toBeInstanceOf(PagePolicy::class);
});

it('answers every role and access level the way the project does', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    string $ability,
    bool $allowed,
): void {
    [$page, $actor] = pageFor($role, $access);

    expect(Gate::forUser($actor)->allows($ability, $page))->toBe($allowed);
})->with([
    'owner editing' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'update', true],
    'owner deleting' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'delete', true],
    'editor editing' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'update', true],
    'editor deleting' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'delete', true],
    'commenter editing' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'update', false],
    'viewer editing' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'update', false],
    'viewer deleting' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'delete', false],
    /*
     * No row of their own, on a workspace-visible project: the project's `default_access_level`
     * answers, and it is `editor` (TASK-260-001).
     */
    'workspace admin with no project access' => [WorkspaceRole::Admin, null, 'update', true],
    'guest given the project as an editor' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'update', false],
    'member reading' => [WorkspaceRole::Member, null, 'view', true],
    'guest reading a workspace-visible project' => [WorkspaceRole::Guest, null, 'view', false],
]);

it('refuses somebody from another workspace holding a valid id', function (): void {
    [$page] = pageFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    [, $stranger] = workspaceWith(WorkspaceRole::Owner);

    expect(Gate::forUser($stranger)->allows('view', $page))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('update', $page))->toBeFalse();
});

it('refuses a private project to a member who was never given it', function (): void {
    [$page, $actor] = pageFor(WorkspaceRole::Member, null, ProjectVisibility::Private);

    expect(Gate::forUser($actor)->allows('view', $page))->toBeFalse();
});

it('asks the project whether a page may be started at all', function (): void {
    [, $actor, $project] = pageFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    [, $viewer, $viewerProject] = pageFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);

    expect(Gate::forUser($actor)->allows('createPage', $project))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('createPage', $viewerProject))->toBeFalse();
});
