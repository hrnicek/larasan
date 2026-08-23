<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * Every role against the four places a task can be: a workspace-visible project, a private one
 * they were given, a private one they were not, and no project at all. Outcomes written out,
 * because search is the one screen where a wrong answer is invisible — nobody notices a row that
 * should not be there, and nobody notices one that is missing.
 */

/**
 * A task carrying the search term, placed as described, and somebody of the given role.
 *
 * @return array{Workspace, User}
 */
function searchable(WorkspaceRole $role, string $placement): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $task = Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);

    if ($placement === 'nowhere') {
        return [$workspace, $actor];
    }

    $project = Project::factory()->in($workspace)->create([
        'visibility' => $placement === 'workspace' ? ProjectVisibility::Workspace : ProjectVisibility::Private,
    ]);

    if ($placement === 'private given') {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess(ProjectAccessLevel::Viewer)->create();
    }

    TaskProjectMembership::factory()->placing($task, $project)->create();

    return [$workspace, $actor];
}

it('answers a search the same way for each role and each placement', function (
    WorkspaceRole $role,
    string $placement,
    bool $found,
): void {
    [$workspace, $actor] = searchable($role, $placement);

    $this->actingAs($actor)
        ->get(route('search.index', ['q' => 'login']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('tasks', $found ? 1 : 0));
})->with([
    // A workspace-visible project is reachable by any member whatever their project access; a
    // guest reaches only what they were given.
    'owner, workspace project' => [WorkspaceRole::Owner, 'workspace', true],
    'admin, workspace project' => [WorkspaceRole::Admin, 'workspace', true],
    'member, workspace project' => [WorkspaceRole::Member, 'workspace', true],
    'guest, workspace project' => [WorkspaceRole::Guest, 'workspace', false],

    'owner, private project they were given' => [WorkspaceRole::Owner, 'private given', true],
    'member, private project they were given' => [WorkspaceRole::Member, 'private given', true],
    'guest, private project they were given' => [WorkspaceRole::Guest, 'private given', true],

    // The row that must never appear: a private project nobody added them to.
    'owner, private project they were not given' => [WorkspaceRole::Owner, 'private', false],
    'admin, private project they were not given' => [WorkspaceRole::Admin, 'private', false],
    'member, private project they were not given' => [WorkspaceRole::Member, 'private', false],
    'guest, private project they were not given' => [WorkspaceRole::Guest, 'private', false],

    // Workspace work: a member's to find, and never a guest's.
    'owner, no project' => [WorkspaceRole::Owner, 'nowhere', true],
    'member, no project' => [WorkspaceRole::Member, 'nowhere', true],
    'guest, no project' => [WorkspaceRole::Guest, 'nowhere', false],
]);

it('never returns another workspace s task, whatever the term', function (WorkspaceRole $role): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    memberOf($elsewhere, WorkspaceRole::Owner, user: $actor);

    Task::factory()->in($elsewhere)->create(['title' => 'Fix the login screen']);

    foreach (['login', 'fix', 'screen', 'log'] as $term) {
        $this->actingAs($actor)
            ->get(route('search.index', ['q' => $term]))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('tasks', 0));
    }
})->with([
    'owner' => [WorkspaceRole::Owner],
    'admin' => [WorkspaceRole::Admin],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('returns nothing rather than a row somebody cannot open', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);
    TaskProjectMembership::factory()->placing($task, $private)->create();

    /*
     * Not an empty-looking row, not a count that says one: nothing. A result somebody cannot
     * open would tell them the task exists, which is what the private project was for.
     */
    $this->actingAs($actor)
        ->get(route('search.index', ['q' => 'login']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 0)
            ->where('meta.total', 0));
});

it('turns away a revoked membership', function (WorkspaceRole $role): void {
    $workspace = Workspace::factory()->create();
    $revoked = memberOf($workspace, $role, WorkspaceMembershipStatus::Revoked);
    Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);

    // No live membership is no current workspace, and a search without one is not a search.
    $this->actingAs($revoked)->get(route('search.index', ['q' => 'login']))->assertNotFound();
})->with([
    'owner' => [WorkspaceRole::Owner],
    'member' => [WorkspaceRole::Member],
    'guest' => [WorkspaceRole::Guest],
]);

it('turns away everybody who is not signed in', function (): void {
    $this->get(route('search.index', ['q' => 'login']))->assertRedirect(route('login'));
});
