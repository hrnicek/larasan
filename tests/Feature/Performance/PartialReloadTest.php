<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Each read is recognised by SQL only it emits: the sidebar's `stars_exists`, the switcher's
 * `"workspaces"."slug"`, the badge's and paginated lists' `as "aggregate"`, the board's
 * `count(*) as total` and the panel's `"task_followers"`.
 */

/**
 * Sends the asset version so the request passes through the middleware that shares the props under test.
 *
 * @return array<string, string>
 */
function partialReloadOf(string $component, string ...$props): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => implode(',', $props),
    ];
}

function sqlOf(Closure $request): string
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $request();

    $sql = implode("\n", array_map(fn (array $query): string => (string) $query['query'], DB::getQueryLog()));

    DB::disableQueryLog();

    return $sql;
}

/**
 * @return array{User, Project, Task}
 */
function boardToReload(): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();
    $section = Section::factory()->in($project)->at(Section::POSITION_GAP)->create();
    $tasks = Task::factory()->count(3)->in($workspace)->create(['assignee_id' => $actor->id]);

    foreach ($tasks->values() as $index => $task) {
        TaskProjectMembership::factory()->placing($task, $project)->create([
            'section_id' => $section->id,
            'position' => ($index + 1) * TaskProjectMembership::POSITION_GAP,
        ]);
    }

    $actor->forceFill(['current_workspace_id' => $workspace->id])->save();

    return [$actor, $project, $tasks->first()];
}

it('opens a panel over the board without reading the board, the header or the shell', function (): void {
    [$actor, $project, $task] = boardToReload();

    $sql = sqlOf(fn () => $this->actingAs($actor)
        ->get(
            route('projects.show', [$project, 'view' => 'board', 'task' => $task->id]),
            partialReloadOf('projects/Show', 'taskDetail'),
        )
        ->assertOk()
        ->assertJsonPath('props.taskDetail.task.id', $task->id)
        ->assertJsonMissingPath('props.board'));

    expect($sql)
        ->toContain('"task_followers"')
        ->not->toContain('count(*) as total')
        ->not->toContain('stars_exists')
        ->not->toContain('"workspaces"."slug"')
        ->not->toContain('from "notifications"');
});

it('refreshes the board without reading the panel open over it', function (): void {
    [$actor, $project, $task] = boardToReload();

    $sql = sqlOf(fn () => $this->actingAs($actor)
        ->get(
            route('projects.show', [$project, 'view' => 'board', 'task' => $task->id]),
            partialReloadOf('projects/Show', 'board'),
        )
        ->assertOk()
        ->assertJsonMissingPath('props.taskDetail'));

    expect($sql)
        ->toContain('count(*) as total')
        ->not->toContain('"task_followers"')
        ->not->toContain('stars_exists');
});

it('still refuses a panel for a task out of reach when the panel is not what was asked for', function (): void {
    [$actor, $project] = boardToReload();
    $elsewhere = Task::factory()->create();

    $this->actingAs($actor)
        ->get(
            route('projects.show', [$project, 'view' => 'board', 'task' => $elsewhere->id]),
            partialReloadOf('projects/Show', 'board'),
        )
        ->assertNotFound();
});

it('opens a panel over a list without reading the list or the shell', function (string $route, array $query, string $component, string $list): void {
    [$actor, , $task] = boardToReload();

    $sql = sqlOf(fn () => $this->actingAs($actor)
        ->get(route($route, [...$query, 'task' => $task->id]), partialReloadOf($component, 'taskDetail'))
        ->assertOk()
        ->assertJsonPath('props.taskDetail.task.id', $task->id)
        ->assertJsonMissingPath("props.{$list}"));

    expect($sql)
        ->toContain('"task_followers"')
        ->not->toContain('as "aggregate"')
        ->not->toContain('stars_exists')
        ->not->toContain('"workspaces"."slug"');
})->with([
    'my tasks' => ['my-tasks.index', [], 'my-tasks/Index', 'tasks'],
    'the inbox' => ['inbox.index', [], 'inbox/Index', 'notifications'],
    'search' => ['search.index', ['q' => 'task'], 'search/Index', 'tasks'],
]);

it('reads a list once however many of its keys a reload asks for', function (): void {
    [$actor] = boardToReload();

    $sql = sqlOf(fn () => $this->actingAs($actor)
        ->get(route('my-tasks.index'), partialReloadOf('my-tasks/Index', 'tasks', 'meta'))
        ->assertOk()
        ->assertJsonPath('props.meta.page', 1));

    expect(substr_count($sql, 'as "aggregate"'))->toBe(1);
});
