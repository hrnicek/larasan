<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * The task detail panel, on the four screens that can open one (TASK-200-004).
 *
 * The panel is an address — `?task=` on whichever screen it was opened from — and an address is
 * a second door into a task. These assert it refuses in exactly the places `tasks.show` refuses,
 * on every screen, because the rule now lives in one trait and a trait is only as good as the
 * screens actually using it.
 */

/**
 * The four screens that can open a panel, as route names, with a closure that puts the actor
 * somewhere the screen will render.
 *
 * @return array<string, array{string, Closure(Workspace, User): array<string, mixed>}>
 */
function panelScreens(): array
{
    return [
        'the project' => ['projects.show', fn (Workspace $workspace): array => ['project' => Project::factory()->in($workspace)->create()]],
        'My Tasks' => ['my-tasks.index', fn (): array => []],
        'the Inbox' => ['inbox.index', fn (): array => []],
        'search' => ['search.index', fn (): array => []],
    ];
}

dataset('panel screens', [
    'the project' => ['projects.show', true],
    'My Tasks' => ['my-tasks.index', false],
    'the Inbox' => ['inbox.index', false],
    'search' => ['search.index', false],
]);

/**
 * The screen's address, with the panel's task appended when one is given.
 */
function panelUrl(string $route, bool $needsProject, Workspace $workspace, ?string $task = null): string
{
    $parameters = $needsProject
        ? ['project' => Project::factory()->in($workspace)->create()]
        : [];

    if ($task !== null) {
        $parameters['task'] = $task;
    }

    return route($route, $parameters);
}

it('sends no panel when the address does not ask for one', function (string $route, bool $needsProject): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);

    $this->actingAs($actor)
        ->get(panelUrl($route, $needsProject, $workspace))
        ->assertOk()
        // Null rather than absent: a partial reload has to tell "no panel" from "not sent this
        // time", and an absent prop is indistinguishable from the second.
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('taskDetail', null));
})->with('panel screens');

it('opens the panel for a task the reader may read', function (string $route, bool $needsProject): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);
    $task = Task::factory()->in($workspace)->create(['title' => 'Write it down']);

    $this->actingAs($actor)
        ->get(panelUrl($route, $needsProject, $workspace, $task->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('taskDetail.task.id', $task->id)
            ->where('taskDetail.task.title', 'Write it down')
            ->has('members')
            ->has('priorities'));
})->with('panel screens');

it('refuses a task from another workspace', function (string $route, bool $needsProject): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);

    // Somebody else's workspace, and this actor is an owner in their own — which buys nothing
    // here. The panel resolves inside the workspace before the policy is ever asked.
    $elsewhere = Task::factory()->in(Workspace::factory()->create())->create();

    $this->actingAs($actor)
        ->get(panelUrl($route, $needsProject, $workspace, $elsewhere->id))
        ->assertNotFound();
})->with('panel screens');

it('refuses a task in a private project the reader was never given', function (string $route, bool $needsProject): void {
    $workspace = Workspace::factory()->create();
    // A member of the workspace, not of the project: reach is a project-level question and being
    // in the workspace does not answer it (TASK-070-017).
    $actor = memberOf($workspace, WorkspaceRole::Member);

    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();

    // The placement row directly rather than through the Action: what is under test is who may
    // read the task afterwards, and routing the setup through an authorization check of its own
    // would only make this file depend on that check passing.
    TaskProjectMembership::factory()->placing($task, $private)->create();

    $this->actingAs($actor)
        ->get(panelUrl($route, $needsProject, $workspace, $task->id))
        ->assertNotFound();
})->with('panel screens');

it('answers an id that names nothing the same way as one it may not read', function (string $route, bool $needsProject): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);

    // The same 404 as the two above. A different answer here would make the panel a way of
    // asking whether a task exists.
    $this->actingAs($actor)
        ->get(panelUrl($route, $needsProject, $workspace, '0192f3c7-0000-7000-8000-000000000000'))
        ->assertNotFound();
})->with('panel screens');
