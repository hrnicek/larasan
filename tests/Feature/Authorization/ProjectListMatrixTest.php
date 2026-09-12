<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{Project, User}
 */
function matrixListProject(
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    ProjectVisibility $visibility = ProjectVisibility::Workspace,
): array {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create(['visibility' => $visibility]);

    if ($access !== null) {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();
    }

    $section = Section::factory()->in($project)->create(['name' => 'Backlog']);

    TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(['title' => 'A card']), $project)
        ->inSection($section)
        ->create();

    return [$project, $actor];
}

it('answers each role and access level the same way at the list', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    string $outcome,
    bool $mayEdit,
): void {
    [$project, $actor] = matrixListProject($role, $access);

    $response = $this->actingAs($actor)->get(route('projects.show', $project));

    if ($outcome === 'missing') {
        $response->assertNotFound();

        return;
    }

    $response->assertOk()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->has('list.sections.0.tasks', 1)
        ->where('list.can.createTask', $mayEdit)
        ->where('list.can.updateTask', $mayEdit)
        ->where('list.can.deleteTask', $mayEdit));
})->with([
    'owner as project owner' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'visible', true],
    'owner as editor' => [WorkspaceRole::Owner, ProjectAccessLevel::Editor, 'visible', true],
    'owner as commenter' => [WorkspaceRole::Owner, ProjectAccessLevel::Commenter, 'visible', false],
    'owner as viewer' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'visible', false],
    // Without a membership row the project's default access level, `editor`, applies; guests get no default. See ADR-0020.
    'owner with no project membership' => [WorkspaceRole::Owner, null, 'visible', true],

    'admin as project owner' => [WorkspaceRole::Admin, ProjectAccessLevel::Owner, 'visible', true],
    'admin as editor' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'visible', true],
    'admin as commenter' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'visible', false],
    'admin as viewer' => [WorkspaceRole::Admin, ProjectAccessLevel::Viewer, 'visible', false],
    'admin with no project membership' => [WorkspaceRole::Admin, null, 'visible', true],

    'member as project owner' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'visible', true],
    'member as editor' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'visible', true],
    'member as commenter' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'visible', false],
    'member as viewer' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'visible', false],
    'member with no project membership' => [WorkspaceRole::Member, null, 'visible', true],

    // Workspace visibility grants a guest nothing, and guests never hold `task.update`. See ADR-0010.
    'guest as editor' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'visible', false],
    'guest as viewer' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'visible', false],
    'guest with no project membership' => [WorkspaceRole::Guest, null, 'missing', false],
]);

it('hides a private project from everybody who was not given it', function (WorkspaceRole $role): void {
    [$project, $actor] = matrixListProject($role, null, ProjectVisibility::Private);

    // 404 rather than an empty board, which would confirm the project exists.
    $this->actingAs($actor)
        ->get(route('projects.show', $project))
        ->assertNotFound();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('hides a project in another workspace from everybody', function (WorkspaceRole $role): void {
    [$project] = matrixListProject(WorkspaceRole::Owner, ProjectAccessLevel::Owner);
    $stranger = memberOf(Workspace::factory()->create(), $role);

    $this->actingAs($stranger)
        ->get(route('projects.show', $project))
        ->assertNotFound();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('refuses the list to somebody who is not signed in', function (): void {
    [$project] = matrixListProject(WorkspaceRole::Owner, ProjectAccessLevel::Owner);

    $this->get(route('projects.show', $project))->assertRedirect(route('login'));
});
