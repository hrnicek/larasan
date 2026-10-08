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

/**
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
        // Null rather than absent, so a partial reload can tell "no panel" from "not sent".
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

    $elsewhere = Task::factory()->in(Workspace::factory()->create())->create();

    $this->actingAs($actor)
        ->get(panelUrl($route, $needsProject, $workspace, $elsewhere->id))
        ->assertNotFound();
})->with('panel screens');

it('refuses a task in a private project the reader was never given', function (string $route, bool $needsProject): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();

    TaskProjectMembership::factory()->placing($task, $private)->create();

    $this->actingAs($actor)
        ->get(panelUrl($route, $needsProject, $workspace, $task->id))
        ->assertNotFound();
})->with('panel screens');

it('answers an id that names nothing the same way as one it may not read', function (string $route, bool $needsProject): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);

    $this->actingAs($actor)
        ->get(panelUrl($route, $needsProject, $workspace, '0192f3c7-0000-7000-8000-000000000000'))
        ->assertNotFound();
})->with('panel screens');
