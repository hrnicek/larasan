<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\ProjectBoardQuery;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Actions\DeleteTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * @param  list<string>  $expanded
 * @return array<string, mixed>
 */
function boardOf(Project $project, User $actor, array $expanded = []): array
{
    return app(ProjectBoardQuery::class)($project, $actor, $expanded);
}

/**
 * `$count` cards in one column, titled by their position so the order reads.
 */
function fill(Workspace $workspace, Project $project, ?Section $section, int $count, string $prefix = 'Card'): void
{
    foreach (range(1, $count) as $slot) {
        $factory = TaskProjectMembership::factory()
            ->placing(Task::factory()->in($workspace)->create(['title' => "{$prefix} {$slot}"]), $project)
            ->at($slot * SparsePosition::GAP);

        ($section === null ? $factory : $factory->inSection($section))->create();
    }
}

it('draws the columns in order, each with its cards in position order', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $first = Section::factory()->in($project)->at(SparsePosition::GAP)->create(['name' => 'Backlog']);
    $second = Section::factory()->in($project)->at(2 * SparsePosition::GAP)->create(['name' => 'Doing']);

    fill($workspace, $project, $first, 2, 'Backlog');
    fill($workspace, $project, $second, 1, 'Doing');

    $board = boardOf($project, $actor);

    expect(array_column($board['columns'], 'name'))->toBe(['Backlog', 'Doing'])
        ->and(array_column($board['columns'][0]['tasks'], 'title'))->toBe(['Backlog 1', 'Backlog 2'])
        ->and(array_column($board['columns'][1]['tasks'], 'title'))->toBe(['Doing 1']);
});

it('stops at a page and says how many it did not draw', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $column = Section::factory()->in($project)->create(['name' => 'Backlog']);

    fill($workspace, $project, $column, ProjectBoardQuery::PER_COLUMN + 5);

    $board = boardOf($project, $actor);

    // The count is the whole column and the page is what a reader gets: "load more" is a fact
    // the server states, not a guess the client makes from a page size.
    expect($board['columns'][0]['tasks'])->toHaveCount(ProjectBoardQuery::PER_COLUMN)
        ->and($board['columns'][0]['count'])->toBe(ProjectBoardQuery::PER_COLUMN + 5)
        ->and($board['columns'][0]['hasMore'])->toBeTrue()
        ->and($board['perColumn'])->toBe(ProjectBoardQuery::PER_COLUMN);
});

it('pages each column separately rather than the board as a whole', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $long = Section::factory()->in($project)->at(SparsePosition::GAP)->create(['name' => 'Long']);
    $short = Section::factory()->in($project)->at(2 * SparsePosition::GAP)->create(['name' => 'Short']);

    fill($workspace, $project, $long, ProjectBoardQuery::PER_COLUMN + 3, 'Long');
    fill($workspace, $project, $short, 2, 'Short');

    $board = boardOf($project, $actor);

    // A board-wide limit would have swallowed the short column behind the long one.
    expect($board['columns'][0]['tasks'])->toHaveCount(ProjectBoardQuery::PER_COLUMN)
        ->and($board['columns'][1]['tasks'])->toHaveCount(2)
        ->and($board['columns'][1]['hasMore'])->toBeFalse();
});

it('draws the first page of a column, not any page', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $column = Section::factory()->in($project)->create();

    fill($workspace, $project, $column, ProjectBoardQuery::PER_COLUMN + 2);

    $board = boardOf($project, $actor);
    $titles = array_column($board['columns'][0]['tasks'], 'title');

    expect($titles[0])->toBe('Card 1')
        ->and($titles[ProjectBoardQuery::PER_COLUMN - 1])->toBe('Card '.ProjectBoardQuery::PER_COLUMN);
});

it('gives a reader the whole column when they ask for it', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $column = Section::factory()->in($project)->create();

    fill($workspace, $project, $column, ProjectBoardQuery::PER_COLUMN + 4);

    $board = boardOf($project, $actor, [$column->id]);

    expect($board['columns'][0]['tasks'])->toHaveCount(ProjectBoardQuery::PER_COLUMN + 4)
        ->and($board['columns'][0]['hasMore'])->toBeFalse();
});

it('expands one column without expanding the others', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $asked = Section::factory()->in($project)->at(SparsePosition::GAP)->create();
    $other = Section::factory()->in($project)->at(2 * SparsePosition::GAP)->create();

    fill($workspace, $project, $asked, ProjectBoardQuery::PER_COLUMN + 2, 'Asked');
    fill($workspace, $project, $other, ProjectBoardQuery::PER_COLUMN + 2, 'Other');

    $board = boardOf($project, $actor, [$asked->id]);

    expect($board['columns'][0]['tasks'])->toHaveCount(ProjectBoardQuery::PER_COLUMN + 2)
        ->and($board['columns'][1]['tasks'])->toHaveCount(ProjectBoardQuery::PER_COLUMN);
});

it('pages and expands the ungrouped bucket like any other column', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    Section::factory()->in($project)->create();

    fill($workspace, $project, null, ProjectBoardQuery::PER_COLUMN + 1, 'Loose');

    $paged = boardOf($project, $actor);
    $expanded = boardOf($project, $actor, [ProjectBoardQuery::UNGROUPED]);

    $bucket = fn (array $board): array => $board['columns'][count($board['columns']) - 1];

    expect($bucket($paged)['id'])->toBeNull()
        ->and($bucket($paged)['tasks'])->toHaveCount(ProjectBoardQuery::PER_COLUMN)
        ->and($bucket($paged)['hasMore'])->toBeTrue()
        ->and($bucket($expanded)['tasks'])->toHaveCount(ProjectBoardQuery::PER_COLUMN + 1);
});

it('counts a column the same way it fills it', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $column = Section::factory()->in($project)->create();
    fill($workspace, $project, $column, 3);

    $going = Task::query()
        ->whereKey($column->placements()->orderByDesc('position')->value('task_id'))
        ->sole();

    app(DeleteTask::class)->handle($going, memberOf($workspace, WorkspaceRole::Owner));

    $board = boardOf($project, $actor);

    // A soft-deleted task cannot leave a count of three above two cards (TASK-050-013).
    expect($board['columns'][0]['count'])->toBe(2)
        ->and($board['columns'][0]['tasks'])->toHaveCount(2);
});

it('carries what a card draws, and no more', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $assignee = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create(['assignee_id' => $assignee->id]);
    Task::factory()->in($workspace)->create(['parent_id' => $task->id]);
    Task::factory()->in($workspace)->create(['parent_id' => $task->id]);
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $card = boardOf($project, $actor)['columns'][0]['tasks'][0];

    expect(array_keys($card))
        ->toBe(['placementId', 'id', 'title', 'completedAt', 'dueAt', 'priority', 'comments', 'subtasks', 'assignee'])
        ->and($card['subtasks'])->toBe(2)
        ->and($card['assignee']['id'])->toBe($assignee->id);
});

it('reads a wide board without a query per column or per card', function (): void {
    [$workspace, $project, $actor] = placeableProject();

    foreach (range(1, 4) as $index) {
        $column = Section::factory()->in($project)->at($index * SparsePosition::GAP)->create();
        fill($workspace, $project, $column, 6, "Column {$index}");
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $board = boardOf($project, $actor);

    /*
     * Four columns, twenty-four cards: the counts, the paged ids, the placements, the tasks,
     * the assignees, the sections, and the actor's memberships. A bound rather than an exact
     * figure, because the membership lookups are memoised per request (TASK-040-020).
     */
    expect($board['columns'])->toHaveCount(4)
        ->and(count($queries))->toBeLessThanOrEqual(9);
});

it('tells a viewer what they may not do, and still draws the board', function (): void {
    [$workspace, $project, $viewer] = placeableProject(ProjectAccessLevel::Viewer);
    $column = Section::factory()->in($project)->create();
    fill($workspace, $project, $column, 2);

    $board = boardOf($project, $viewer);

    expect($board['can'])->toBe(['createTask' => false, 'updateTask' => false, 'deleteTask' => false])
        ->and($board['columns'][0]['tasks'])->toHaveCount(2);
});

it('shows nothing from another project', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $elsewhere = Project::factory()->in($workspace)->create();
    fill($workspace, $project, null, 1, 'Mine');
    fill($workspace, $elsewhere, null, 1, 'Theirs');

    $board = boardOf($project, $actor);

    expect($board['columns'][0]['tasks'])->toHaveCount(1)
        ->and($board['columns'][0]['tasks'][0]['title'])->toBe('Mine 1');
});

it('counts the comments on a card without a query per card', function (): void {
    [$workspace, $project, $actor] = placeableProject();

    foreach (range(1, 12) as $index) {
        $task = Task::factory()->in($workspace)->create();
        TaskProjectMembership::factory()->placing($task, $project)->at($index * SparsePosition::GAP)->create();

        Comment::factory()->on($task)->count($index % 3)->create();

        // Removed comments are not counted: the thread shows them so the conversation still
        // reads correctly, and a card that counted them would promise something that is not
        // there.
        Comment::factory()->on($task)->create()->delete();
    }

    DB::enableQueryLog();
    $board = boardOf($project, $actor);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // A subquery per count, not a query per card — the bound this query has asserted since
    // Phase 090 does not move because a column was added to it.
    expect(array_column($board['columns'][0]['tasks'], 'comments'))
        ->toBe([1, 2, 0, 1, 2, 0, 1, 2, 0, 1, 2, 0])
        ->and(count($queries))->toBeLessThanOrEqual(8);
});
