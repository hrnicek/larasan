<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\MyTasksTab;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\MyTasksQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * @return array{tasks: list<array<string, mixed>>, meta: array<string, mixed>}
 */
function myTasks(Workspace $workspace, User $actor, MyTasksTab $tab = MyTasksTab::Today, int $page = 1, int $perPage = 25): array
{
    return app(MyTasksQuery::class)($workspace, $actor, $tab, $page, $perPage);
}

it('shows only what is due today', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    Task::factory()->in($workspace)->create(['title' => 'Today', 'assignee_id' => $actor->id, 'due_at' => now()]);
    Task::factory()->in($workspace)->create(['title' => 'Late tonight', 'assignee_id' => $actor->id, 'due_at' => now()->endOfDay()]);
    Task::factory()->in($workspace)->create(['title' => 'Tomorrow', 'assignee_id' => $actor->id, 'due_at' => now()->addDay()]);
    Task::factory()->in($workspace)->create(['title' => 'Yesterday', 'assignee_id' => $actor->id, 'due_at' => now()->subDay()]);

    expect(array_column(myTasks($workspace, $actor)['tasks'], 'title'))->toBe(['Today', 'Late tonight']);
});

it('shows what is late, and only that', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    Task::factory()->in($workspace)->create(['title' => 'Last week', 'assignee_id' => $actor->id, 'due_at' => now()->subWeek()]);
    Task::factory()->in($workspace)->create(['title' => 'Today', 'assignee_id' => $actor->id, 'due_at' => now()]);
    Task::factory()->in($workspace)->create([
        'title' => 'Late but finished',
        'assignee_id' => $actor->id,
        'due_at' => now()->subWeek(),
        'completed_at' => now(),
    ]);

    // Something finished late is not overdue; it is done.
    expect(array_column(myTasks($workspace, $actor, MyTasksTab::Overdue)['tasks'], 'title'))->toBe(['Last week']);
});

it('puts undated work in Upcoming rather than nowhere', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    Task::factory()->in($workspace)->create(['title' => 'Next week', 'assignee_id' => $actor->id, 'due_at' => now()->addWeek()]);
    Task::factory()->in($workspace)->create(['title' => 'No date', 'assignee_id' => $actor->id, 'due_at' => null]);

    /*
     * The four tabs are the whole screen, so a task matching none of them would be work somebody
     * had been given and could not find. Dated first, because the undated are not due soonest —
     * they are simply not due.
     */
    expect(array_column(myTasks($workspace, $actor, MyTasksTab::Upcoming)['tasks'], 'title'))
        ->toBe(['Next week', 'No date']);
});

it('sorts by due date and then by priority', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    // The same instant for all three, written out: `due_at` is `timestamp(0)`, so rows created
    // a second apart would be sorted by date before priority ever came into it.
    $due = now()->startOfHour();

    Task::factory()->in($workspace)->create(['title' => 'Low today', 'assignee_id' => $actor->id, 'due_at' => $due, 'priority' => TaskPriority::Low]);
    Task::factory()->in($workspace)->create(['title' => 'Urgent today', 'assignee_id' => $actor->id, 'due_at' => $due, 'priority' => TaskPriority::Urgent]);
    Task::factory()->in($workspace)->create(['title' => 'High today', 'assignee_id' => $actor->id, 'due_at' => $due, 'priority' => TaskPriority::High]);

    // The enum's values sort the wrong way as strings, which is why the order is written out.
    expect(array_column(myTasks($workspace, $actor)['tasks'], 'title'))
        ->toBe(['Urgent today', 'High today', 'Low today']);
});

it('reads a finished list newest first', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    Task::factory()->in($workspace)->create(['title' => 'Older', 'assignee_id' => $actor->id, 'completed_at' => now()->subDays(3)]);
    Task::factory()->in($workspace)->create(['title' => 'Newer', 'assignee_id' => $actor->id, 'completed_at' => now()->subHour()]);

    expect(array_column(myTasks($workspace, $actor, MyTasksTab::Completed)['tasks'], 'title'))->toBe(['Newer', 'Older']);
});

it('shows nobody else s work', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $other = memberOf($workspace);

    Task::factory()->in($workspace)->create(['title' => 'Mine', 'assignee_id' => $actor->id, 'due_at' => now()]);
    Task::factory()->in($workspace)->create(['title' => 'Theirs', 'assignee_id' => $other->id, 'due_at' => now()]);
    Task::factory()->in($workspace)->create(['title' => 'Nobody s', 'due_at' => now()]);

    expect(array_column(myTasks($workspace, $actor)['tasks'], 'title'))->toBe(['Mine']);
});

it('proves its own workspace scope', function (): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace);
    memberOf($elsewhere, user: $actor);

    Task::factory()->in($workspace)->create(['title' => 'Here', 'assignee_id' => $actor->id, 'due_at' => now()]);
    Task::factory()->in($elsewhere)->create(['title' => 'There', 'assignee_id' => $actor->id, 'due_at' => now()]);

    // The same person in two workspaces has two lists, and switching is what changes this one.
    expect(array_column(myTasks($workspace, $actor)['tasks'], 'title'))->toBe(['Here']);
});

it('names only the projects the reader can reach', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $open = Project::factory()->in($workspace)->create(['name' => 'Open']);
    $private = Project::factory()->in($workspace)->create(['name' => 'Private', 'visibility' => ProjectVisibility::Private]);

    $task = Task::factory()->in($workspace)->create(['assignee_id' => $actor->id, 'due_at' => now()]);
    TaskProjectMembership::factory()->placing($task, $open)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();

    /*
     * The task is theirs and stays visible; the project they were never given does not appear
     * beside it. Naming it would leak a project through a task they are allowed to see.
     */
    $row = myTasks($workspace, $actor)['tasks'][0];

    expect(array_column($row['projects'], 'name'))->toBe(['Open']);
});

it('keeps a task whose only project the reader cannot open', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);

    $task = Task::factory()->in($workspace)->create(['title' => 'Assigned to me', 'assignee_id' => $actor->id, 'due_at' => now()]);
    TaskProjectMembership::factory()->placing($task, $private)->create();

    // Work somebody has been given is theirs to see, wherever it was filed.
    $rows = myTasks($workspace, $actor)['tasks'];

    expect(array_column($rows, 'title'))->toBe(['Assigned to me'])
        ->and($rows[0]['projects'])->toBe([]);
});

it('pages without reading everything', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    foreach (range(1, 7) as $index) {
        Task::factory()->in($workspace)->create([
            'title' => "Task {$index}",
            'assignee_id' => $actor->id,
            'due_at' => now()->addMinutes($index),
        ]);
    }

    $first = myTasks($workspace, $actor, perPage: 3);
    $last = myTasks($workspace, $actor, page: 3, perPage: 3);

    expect($first['tasks'])->toHaveCount(3)
        ->and($first['meta']['total'])->toBe(7)
        ->and($first['meta']['hasMore'])->toBeTrue()
        ->and($last['tasks'])->toHaveCount(1)
        ->and($last['meta']['hasMore'])->toBeFalse();
});

it('reads a page of tasks in several projects without a query per row', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $projects = Project::factory()->in($workspace)->count(3)->create();

    foreach (range(1, 10) as $index) {
        $task = Task::factory()->in($workspace)->create(['assignee_id' => $actor->id, 'due_at' => now()]);

        foreach ($projects as $project) {
            TaskProjectMembership::factory()->placing($task, $project)->at($index * SparsePosition::GAP)->create();
        }
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $result = myTasks($workspace, $actor);

    // Ten tasks in three projects each: the count, the page, the placements and their projects.
    expect($result['tasks'])->toHaveCount(10)
        ->and(count($queries))->toBeLessThanOrEqual(6);
});
