<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Section\Policies\SectionPolicy;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * @return array{Section, User}
 */
function sectionFor(
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

    return [Section::factory()->in($project)->create(), $actor];
}

it('is the policy the gate resolves for a section', function (): void {
    expect(Gate::getPolicyFor(Section::class))->toBeInstanceOf(SectionPolicy::class);
});

it('answers every role and access level the same way the project does', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    string $ability,
    bool $allowed,
): void {
    [$section, $actor] = sectionFor($role, $access);

    expect(Gate::forUser($actor)->allows($ability, $section))->toBe($allowed);
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
     * answers, and it is `editor` (TASK-260-001). The private case below is the one where
     * absence still means nothing.
     */
    'workspace admin with no project access' => [WorkspaceRole::Admin, null, 'update', true],
    'guest given the project as an editor' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'update', false],
    'member reading' => [WorkspaceRole::Member, null, 'view', true],
    'guest reading a workspace-visible project' => [WorkspaceRole::Guest, null, 'view', false],
]);

it('hides a private project section from a workspace owner who was never given it', function (): void {
    [$section, $owner] = sectionFor(WorkspaceRole::Owner, null, ProjectVisibility::Private);

    expect(Gate::forUser($owner)->allows('view', $section))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $section))->toBeFalse();
});

it('denies every ability to someone outside the workspace', function (string $ability): void {
    [$section] = sectionFor(WorkspaceRole::Owner, ProjectAccessLevel::Owner);
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    expect(Gate::forUser($outsider)->allows($ability, $section))->toBeFalse();
})->with(['view', 'update', 'delete']);

it('asks the project whether a section may be created', function (): void {
    [$section, $actor] = sectionFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $viewerProject = $section->project;
    $viewer = memberOf($viewerProject->workspace, WorkspaceRole::Member);
    ProjectMembership::factory()->in($viewerProject)->forUser($viewer)->withAccess(ProjectAccessLevel::Viewer)->create();

    expect(Gate::forUser($actor)->allows('createSection', $viewerProject))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('createSection', $viewerProject))->toBeFalse();
});
