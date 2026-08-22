<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Inertia\Testing\AssertableInertia;

it('requires authentication', function (): void {
    [$workspace] = placeableProject();

    $this->get(route('tasks.show', Task::factory()->in($workspace)->create()))
        ->assertRedirect(route('login'));
});

it('renders a task at its own url', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create(['title' => 'Write it down']);
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('tasks/Show')
            ->where('task.title', 'Write it down')
            ->where('can.update', true)
            ->has('placements', 1));
});

it('renders a task that is in no project at all', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    // A task with no placements is still a task (ADR-0003), and its page says so rather than
    // refusing to render.
    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('placements', 0));
});

it('hides a task in another workspace behind a 404', function (): void {
    [, , $actor] = placeableProject();
    [$otherWorkspace] = placeableProject();

    $this->actingAs($actor)
        ->get(route('tasks.show', Task::factory()->in($otherWorkspace)->create()))
        ->assertNotFound();
});

it('refuses a task that lives only in a project the actor was not given', function (): void {
    [$workspace, , $actor] = placeableProject();
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();

    // They can see the workspace, so the task is not a secret's existence — the access is
    // what is refused (TASK-070-017).
    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertForbidden();
});

it('lets a guest read a task in a project they were given', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $this->actingAs($guest)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('can.update', false)
            ->where('can.comment', true));
});
