<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * The same matrix `ProjectListMatrixTest` runs, at the board. The two views read the same
 * placements through different queries, and a permission that is right in one and wrong in the
 * other is exactly the kind of drift a second matrix catches.
 */

/**
 * @return array{Project, User}
 */
function matrixBoardProject(
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

    $column = Section::factory()->in($project)->create(['name' => 'Backlog']);

    TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(['title' => 'A card']), $project)
        ->inSection($column)
        ->create();

    return [$project, $actor];
}

it('answers each role and access level the same way at the board', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    string $outcome,
    bool $mayEdit,
): void {
    [$project, $actor] = matrixBoardProject($role, $access);

    $response = $this->actingAs($actor)->get(route('projects.show', [
        'project' => $project,
        'view' => ProjectDefaultView::Board->value,
    ]));

    if ($outcome === 'missing') {
        $response->assertNotFound();

        return;
    }

    $response->assertOk()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->has('board.columns.0.tasks', 1)
        ->where('board.can.createTask', $mayEdit)
        ->where('board.can.updateTask', $mayEdit)
        ->where('board.can.deleteTask', $mayEdit));
})->with([
    'owner as project owner' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'visible', true],
    'owner as editor' => [WorkspaceRole::Owner, ProjectAccessLevel::Editor, 'visible', true],
    'owner as commenter' => [WorkspaceRole::Owner, ProjectAccessLevel::Commenter, 'visible', false],
    'owner as viewer' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'visible', false],
    /*
     * No membership row, on a board the whole workspace can open: the project's
     * `default_access_level` answers, and it is `editor` (TASK-260-001). A guest is still
     * refused — they hold projects, never a default.
     */
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

    // A guest reaches what they were given: given the project they may read the board, and
    // they still cannot move a card, because `task.update` is not theirs (ADR-0006, ADR-0010).
    'guest as editor' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'visible', false],
    'guest as viewer' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'visible', false],
    'guest with no project membership' => [WorkspaceRole::Guest, null, 'missing', false],
]);

it('hides a private board from everybody who was not given it', function (WorkspaceRole $role): void {
    [$project, $actor] = matrixBoardProject($role, null, ProjectVisibility::Private);

    $this->actingAs($actor)
        ->get(route('projects.show', ['project' => $project, 'view' => ProjectDefaultView::Board->value]))
        ->assertNotFound();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('gives the board and the list the same answer for the same actor', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
): void {
    [$project, $actor] = matrixBoardProject($role, $access);

    $flags = function (string $view) use ($project, $actor): array {
        $response = $this->actingAs($actor)->get(route('projects.show', ['project' => $project, 'view' => $view]));

        /** @var array<string, mixed> $props */
        $props = $response->viewData('page')['props'];

        return $props[$view === ProjectDefaultView::Board->value ? 'board' : 'list']['can'];
    };

    // Two queries, one answer. A permission right in one view and wrong in the other is the
    // drift this assertion exists to catch.
    expect($flags(ProjectDefaultView::Board->value))->toBe($flags(ProjectDefaultView::List->value));
})->with([
    'editor' => [WorkspaceRole::Member, ProjectAccessLevel::Editor],
    'commenter' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter],
    'viewer' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer],
    'guest given the project' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor],
]);
