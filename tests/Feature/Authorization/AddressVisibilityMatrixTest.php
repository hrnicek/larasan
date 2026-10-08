<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskCollaborator;
use App\Domain\Task\Models\TaskFollower;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

const COLLEAGUE_ADDRESS = 'colleague@pinned.test';

/**
 * @return array{Project, Task, User}
 */
function taskAmongColleagues(WorkspaceRole $role): array
{
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace, $role);
    $colleague = memberOf($workspace, WorkspaceRole::Member, user: User::factory()->create([
        'name' => 'Colleague Pinned',
        'email' => COLLEAGUE_ADDRESS,
    ]));

    $project = Project::factory()->in($workspace)->private()->create();
    ProjectMembership::factory()->in($project)->forUser($colleague)->withAccess(ProjectAccessLevel::Owner)->create();
    ProjectMembership::factory()->in($project)->forUser($reader)->withAccess(ProjectAccessLevel::Editor)->create();

    $task = Task::factory()->in($workspace)->create([
        'title' => 'Pinned errand',
        'assignee_id' => $colleague->id,
        'created_by' => $colleague->id,
        'due_at' => now(),
    ]);
    TaskProjectMembership::factory()->placing($task, $project)->create();
    Comment::factory()->on($task)->by($colleague)->create(['body' => 'A pinned remark']);
    TaskCollaborator::factory()->on($task, $colleague)->create();
    TaskFollower::factory()->create(['task_id' => $task->id, 'user_id' => $colleague->id]);
    Attachment::factory()->attaching(File::factory()->in($workspace)->by($colleague)->create(), $task)->create();

    $reader->forceFill(['current_workspace_id' => $workspace->id])->save();

    return [$project, $task, $reader];
}

/**
 * @param  array<string, mixed>  $props
 * @return list<array<string, mixed>>
 */
function peopleAroundTheTask(array $props): array
{
    $detail = $props['taskDetail'] ?? $props;

    return array_values([
        ...$props['members'],
        $detail['task']['assignee'],
        $detail['task']['creator'],
        ...$detail['collaborators'],
        ...$detail['followers'],
        ...array_column($detail['attachments'], 'uploader'),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 * @return list<array<string, mixed>>
 */
function peopleAt(array $data, string $path): array
{
    /** @var list<array<string, mixed>|null> $people */
    $people = (array) data_get($data, $path);

    return array_values(array_filter($people));
}

/**
 * @param  list<array<string, mixed>>  $people
 */
function expectAddresses(array $people, bool $shown): void
{
    expect($people)->not->toBeEmpty();

    foreach ($people as $person) {
        $shown
            ? expect($person)->toHaveKey('email')
            : expect($person)->not->toHaveKey('email');
    }

    $shown
        ? expect(json_encode($people))->toContain(COLLEAGUE_ADDRESS)
        : expect(json_encode($people))->not->toContain(COLLEAGUE_ADDRESS);
}

dataset('readers', [
    'a guest sees faces only' => [WorkspaceRole::Guest, false],
    'a member sees addresses' => [WorkspaceRole::Member, true],
]);

it("sends colleagues' addresses with the task panel only to a reader who is not a guest", function (string $route, WorkspaceRole $role, bool $shown): void {
    [$project, $task, $reader] = taskAmongColleagues($role);

    $parameters = $route === 'projects.show'
        ? ['project' => $project, 'task' => $task->id]
        : ['task' => $task->id];

    $this->actingAs($reader)
        ->get(route($route, $parameters))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($shown): void {
            expectAddresses(peopleAroundTheTask($page->toArray()['props']), $shown);
        });
})->with([
    'my tasks' => ['my-tasks.index'],
    'the inbox' => ['inbox.index'],
    'search' => ['search.index'],
    'a project' => ['projects.show'],
])->with('readers');

it("sends colleagues' addresses on a task's own page only to a reader who is not a guest", function (WorkspaceRole $role, bool $shown): void {
    [, $task, $reader] = taskAmongColleagues($role);

    $this->actingAs($reader)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($shown): void {
            expectAddresses(peopleAroundTheTask($page->toArray()['props']), $shown);
        });
})->with('readers');

it("sends the project's members' addresses in the share dialog only to a reader who is not a guest", function (WorkspaceRole $role, bool $shown): void {
    [$project, , $reader] = taskAmongColleagues($role);

    $share = $this->actingAs($reader)
        ->withoutMiddleware(HandleInertiaRequests::class)
        ->get(route('projects.show', $project), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'projects/Show',
            'X-Inertia-Partial-Data' => 'share',
        ])
        ->assertOk()
        ->json('props.share');

    expectAddresses($share['members'], $shown);
})->with('readers');

it("returns colleagues' addresses from a people search only to a reader who is not a guest", function (WorkspaceRole $role, bool $shown): void {
    [, , $reader] = taskAmongColleagues($role);

    $people = $this->actingAs($reader)
        ->getJson(route('search.suggestions', ['q' => 'Colleague Pinned', 'kind' => 'people']))
        ->assertOk()
        ->json('results.people');

    expect($people)->toHaveCount(1);

    expectAddresses($people, $shown);
})->with('readers');

it("sends colleagues' addresses on a project view only to a reader who is not a guest", function (string $view, string $path, WorkspaceRole $role, bool $shown): void {
    [$project, , $reader] = taskAmongColleagues($role);

    $this->actingAs($reader)
        ->get(route('projects.show', [$project, 'view' => $view]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($path, $shown): void {
            expectAddresses(peopleAt($page->toArray()['props'], $path), $shown);
        });
})->with([
    'the board' => ['board', 'board.columns.*.tasks.*.assignee'],
    'the list' => ['list', 'list.sections.*.tasks.*.assignee'],
    'the calendar' => ['calendar', 'calendar.days.*.tasks.*.assignee'],
    'the files' => ['files', 'files.files.*.uploader'],
])->with('readers');

it("sends colleagues' addresses on the search screen only to a reader who is not a guest", function (WorkspaceRole $role, bool $shown): void {
    [, , $reader] = taskAmongColleagues($role);

    $this->actingAs($reader)
        ->get(route('search.index', ['q' => 'errand']))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($shown): void {
            expectAddresses(peopleAt($page->toArray()['props'], 'tasks.*.assignee'), $shown);
        });
})->with('readers');

it("sends colleagues' addresses in the palette's tasks and messages only to a reader who is not a guest", function (string $kind, string $term, string $path, WorkspaceRole $role, bool $shown): void {
    [, , $reader] = taskAmongColleagues($role);

    $answer = $this->actingAs($reader)
        ->getJson(route('search.suggestions', ['q' => $term, 'kind' => $kind]))
        ->assertOk()
        ->json();

    expectAddresses(peopleAt($answer, $path), $shown);
})->with([
    'tasks' => ['tasks', 'errand', 'results.tasks.*.assignee'],
    'messages' => ['messages', 'remark', 'results.messages.*.author'],
])->with('readers');

it("sends colleagues' addresses in a task's activity only to a reader who is not a guest", function (WorkspaceRole $role, bool $shown): void {
    [, $task, $reader] = taskAmongColleagues($role);

    $activity = $this->actingAs($reader)
        ->withoutMiddleware(HandleInertiaRequests::class)
        ->get(route('tasks.show', $task), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'tasks/Show',
            'X-Inertia-Partial-Data' => 'activity',
        ])
        ->assertOk()
        ->json('props');

    expectAddresses(peopleAt($activity, 'activity.entries.*.actor'), $shown);
})->with('readers');
