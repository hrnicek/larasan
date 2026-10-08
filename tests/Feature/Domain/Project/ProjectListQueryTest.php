<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\ProjectListQuery;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Actions\DeleteTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * @return array{array<string, mixed>, Project}
 */
function listOf(Project $project, User $actor): array
{
    return [app(ProjectListQuery::class)($project, $actor), $project];
}

function card(Workspace $workspace, Project $project, ?Section $section, int $slot, string $title): TaskProjectMembership
{
    $factory = TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(['title' => $title]), $project)
        ->at($slot * SparsePosition::GAP);

    return ($section === null ? $factory : $factory->inSection($section))->create();
}

it('groups the cards by column, each in position order', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $first = Section::factory()->in($project)->at(SparsePosition::GAP)->create(['name' => 'Backlog']);
    $second = Section::factory()->in($project)->at(2 * SparsePosition::GAP)->create(['name' => 'Doing']);

    card($workspace, $project, $first, 2, 'B');
    card($workspace, $project, $first, 1, 'A');
    card($workspace, $project, $second, 1, 'C');

    [$list] = listOf($project, $actor);

    expect(array_column($list['sections'], 'name'))->toBe(['Backlog', 'Doing'])
        ->and(array_column($list['sections'][0]['tasks'], 'title'))->toBe(['A', 'B'])
        ->and(array_column($list['sections'][1]['tasks'], 'title'))->toBe(['C']);
});

it('renders the ungrouped bucket as a group of its own', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $column = Section::factory()->in($project)->create(['name' => 'Backlog']);
    card($workspace, $project, $column, 1, 'In a column');
    card($workspace, $project, null, 1, 'In no column');

    [$list] = listOf($project, $actor);
    $last = $list['sections'][count($list['sections']) - 1];

    expect($last['id'])->toBeNull()
        ->and(array_column($last['tasks'], 'title'))->toBe(['In no column']);
});

it('leaves the ungrouped group out when there is nothing in it', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $column = Section::factory()->in($project)->create(['name' => 'Backlog']);
    card($workspace, $project, $column, 1, 'Only card');

    [$list] = listOf($project, $actor);

    expect($list['sections'])->toHaveCount(1)
        ->and($list['sections'][0]['name'])->toBe('Backlog');
});

it('lists an empty column rather than hiding it', function (): void {
    [, $project, $actor] = placeableProject();
    Section::factory()->in($project)->create(['name' => 'Nothing here']);

    [$list] = listOf($project, $actor);

    expect($list['sections'])->toHaveCount(1)
        ->and($list['sections'][0]['count'])->toBe(0)
        ->and($list['sections'][0]['tasks'])->toBe([]);
});

it('counts a column the same way it fills it', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $column = Section::factory()->in($project)->create();
    card($workspace, $project, $column, 1, 'A');
    $going = card($workspace, $project, $column, 2, 'B');

    app(DeleteTask::class)->handle($going->task, memberOf($workspace, WorkspaceRole::Owner));

    [$list] = listOf($project, $actor);

    expect($list['sections'][0]['count'])->toBe(1)
        ->and($list['sections'][0]['tasks'])->toHaveCount(1);
});

it('carries the fields a row draws, and no others', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $assignee = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create([
        'title' => 'Write it down',
        'priority' => TaskPriority::High,
        'due_at' => now()->addDay(),
        'assignee_id' => $assignee->id,
    ]);
    TaskProjectMembership::factory()->placing($task, $project)->create();

    [$list] = listOf($project, $actor);
    $row = $list['sections'][0]['tasks'][0];

    expect(array_keys($row))
        ->toBe(['placementId', 'id', 'title', 'completedAt', 'dueAt', 'priority', 'comments', 'fields', 'tags', 'assignee'])
        ->and($row['priority'])->toBe(TaskPriority::High->value)
        ->and($row['dueAt'])->not->toBeNull()
        ->and($row['assignee']['id'])->toBe($assignee->id);
});

it('reads a whole board without a query per card', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $column = Section::factory()->in($project)->create();

    foreach (range(1, 12) as $slot) {
        $task = Task::factory()->in($workspace)->create(['assignee_id' => memberOf($workspace)->id]);
        TaskProjectMembership::factory()->placing($task, $project)->inSection($column)->at($slot * SparsePosition::GAP)->create();
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    [$list] = listOf($project, $actor);

    // A bound rather than an exact count, because membership lookups are memoised per request.
    expect($list['sections'][0]['tasks'])->toHaveCount(12)
        ->and(count($queries))->toBeLessThanOrEqual(10);
});

it('never asks the placement policy per card', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $column = Section::factory()->in($project)->create();
    card($workspace, $project, $column, 1, 'A');
    card($workspace, $project, $column, 2, 'B');

    // Per-card authorization would trip the lazy-loading guard and be an N+1 in production.
    [$list] = listOf($project, $actor);

    expect($list['can'])->toBe([
        'createTask' => true,
        'updateTask' => true,
        'deleteTask' => true,
        'createSection' => true,
        'updateSection' => true,
        'deleteSection' => true,
    ]);
});

it('tells a viewer what they may not do', function (): void {
    [$workspace, $project, $viewer] = placeableProject(ProjectAccessLevel::Viewer);
    card($workspace, $project, null, 1, 'A');

    [$list] = listOf($project, $viewer);

    expect($list['can'])->toBe([
        'createTask' => false,
        'updateTask' => false,
        'deleteTask' => false,
        'createSection' => false,
        'updateSection' => false,
        'deleteSection' => false,
    ])->and($list['sections'][0]['tasks'])->toHaveCount(1);
});

it('closes an archived project to changes while still showing it', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    card($workspace, $project, null, 1, 'A');
    $project->forceFill(['archived_at' => now()])->save();

    [$list] = listOf($project->refresh(), $actor);

    expect($list['can'])->toBe([
        'createTask' => false,
        'updateTask' => false,
        'deleteTask' => false,
        'createSection' => false,
        'updateSection' => false,
        'deleteSection' => false,
    ])->and($list['sections'][0]['tasks'])->toHaveCount(1);
});

it('shows nothing from another project', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $elsewhere = Project::factory()->in($workspace)->create();
    card($workspace, $project, null, 1, 'Mine');
    card($workspace, $elsewhere, null, 1, 'Theirs');

    [$list] = listOf($project, $actor);

    expect($list['sections'][0]['tasks'])->toHaveCount(1)
        ->and($list['sections'][0]['tasks'][0]['title'])->toBe('Mine');
});

it("counts a row's comments and leaves the removed ones out", function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    Comment::factory()->on($task)->count(3)->create();
    Comment::factory()->on($task)->create()->delete();

    [$list] = listOf($project, $actor);

    expect($list['sections'][0]['tasks'][0]['comments'])->toBe(3);
});
