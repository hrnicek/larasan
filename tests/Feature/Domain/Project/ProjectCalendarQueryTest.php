<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\ProjectCalendarQuery;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Actions\DeleteTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * @param  list<string>  $tags
 * @return array<string, mixed>
 */
function calendarOf(Project $project, User $actor, string $month = '2026-07', array $tags = []): array
{
    return app(ProjectCalendarQuery::class)($project, $actor, CarbonImmutable::parse($month.'-01'), $tags);
}

/**
 * The day the grid drew, by its date — the shape every assertion below reads.
 *
 * @param  array<string, mixed>  $calendar
 * @return array<string, mixed>
 */
function day(array $calendar, string $date): array
{
    /** @var list<array<string, mixed>> $days */
    $days = $calendar['days'];

    $found = collect($days)->firstWhere('date', $date);

    expect($found)->not->toBeNull("the grid has no {$date}");

    /** @var array<string, mixed> $found */
    return $found;
}

/**
 * A task in the project, due on that day.
 *
 * Every card gets a slot of its own because an ungrouped placement's position is unique per
 * project — two cards at the same position is a constraint violation, not a tie.
 */
function due(Workspace $workspace, Project $project, string $date, string $title, ?int $slot = null): TaskProjectMembership
{
    return TaskProjectMembership::factory()
        ->placing(
            Task::factory()->in($workspace)->dueAt(CarbonImmutable::parse($date))->create(['title' => $title]),
            $project,
        )
        ->at(($slot ?? nextCalendarSlot()) * SparsePosition::GAP)
        ->create();
}

/** A task in the project that nobody has scheduled. */
function unscheduled(Workspace $workspace, Project $project, string $title): TaskProjectMembership
{
    return TaskProjectMembership::factory()
        ->placing(Task::factory()->in($workspace)->create(['title' => $title]), $project)
        ->at(nextCalendarSlot() * SparsePosition::GAP)
        ->create();
}

function nextCalendarSlot(): int
{
    static $slot = 0;

    return ++$slot;
}

it('draws whole weeks around the month, Monday first', function (): void {
    [, $project, $actor] = placeableProject();

    $calendar = calendarOf($project, $actor, '2026-07');

    /** @var list<array<string, mixed>> $days */
    $days = $calendar['days'];

    // July 2026 starts on a Wednesday and ends on a Friday, so the grid runs from Monday the
    // 29th of June to Sunday the 2nd of August: five whole weeks.
    expect($days)->toHaveCount(35)
        ->and($days[0]['date'])->toBe('2026-06-29')
        ->and($days[34]['date'])->toBe('2026-08-02')
        ->and($calendar['month'])->toBe('2026-07')
        // The spill from either side is drawn, and says it is not this month.
        ->and($days[0]['inMonth'])->toBeFalse()
        ->and($days[2]['inMonth'])->toBeTrue()
        ->and($days[34]['inMonth'])->toBeFalse();
});

it('puts each task on the day it is due, in due order', function (): void {
    [$workspace, $project, $actor] = placeableProject();

    due($workspace, $project, '2026-07-07 15:00', 'Afternoon');
    due($workspace, $project, '2026-07-07 09:00', 'Morning');
    due($workspace, $project, '2026-07-08 09:00', 'Next day');

    $calendar = calendarOf($project, $actor);

    expect(array_column(day($calendar, '2026-07-07')['tasks'], 'title'))->toBe(['Morning', 'Afternoon'])
        ->and(array_column(day($calendar, '2026-07-08')['tasks'], 'title'))->toBe(['Next day'])
        ->and(day($calendar, '2026-07-09')['tasks'])->toBe([]);
});

it('carries the days that spill in from the months on either side', function (): void {
    [$workspace, $project, $actor] = placeableProject();

    due($workspace, $project, '2026-06-30 09:00', 'Last of June');
    due($workspace, $project, '2026-08-01 09:00', 'First of August');

    $calendar = calendarOf($project, $actor);

    expect(array_column(day($calendar, '2026-06-30')['tasks'], 'title'))->toBe(['Last of June'])
        ->and(array_column(day($calendar, '2026-08-01')['tasks'], 'title'))->toBe(['First of August']);
});

it('stops a day at a page and says how many it did not draw', function (): void {
    [$workspace, $project, $actor] = placeableProject();

    foreach (range(1, ProjectCalendarQuery::PER_DAY + 3) as $slot) {
        due($workspace, $project, '2026-07-07 09:00', "Card {$slot}", $slot);
    }

    $cell = day(calendarOf($project, $actor), '2026-07-07');

    expect($cell['tasks'])->toHaveCount(ProjectCalendarQuery::PER_DAY)
        ->and($cell['count'])->toBe(ProjectCalendarQuery::PER_DAY + 3)
        ->and($cell['hasMore'])->toBeTrue();
});

it('keeps the tasks nobody has scheduled in a tray of their own', function (): void {
    [$workspace, $project, $actor] = placeableProject();

    due($workspace, $project, '2026-07-07 09:00', 'Scheduled');
    unscheduled($workspace, $project, 'Someday');

    $calendar = calendarOf($project, $actor);

    /** @var array{count: int, hasMore: bool, tasks: list<array<string, mixed>>} $undated */
    $undated = $calendar['undated'];

    expect($undated['count'])->toBe(1)
        ->and($undated['hasMore'])->toBeFalse()
        ->and(array_column($undated['tasks'], 'title'))->toBe(['Someday']);
});

it('leaves a deleted task out of the grid and out of the counts', function (): void {
    [$workspace, $project, $actor] = placeableProject();

    $card = due($workspace, $project, '2026-07-07 09:00', 'Removed');
    due($workspace, $project, '2026-07-07 10:00', 'Kept');

    /** @var Task $task */
    $task = $card->task;
    app(DeleteTask::class)->handle($task, $actor);

    $cell = day(calendarOf($project, $actor), '2026-07-07');

    // The count and the rows come from one scope, so a cell cannot say more than it draws.
    expect(array_column($cell['tasks'], 'title'))->toBe(['Kept'])
        ->and($cell['count'])->toBe(1);
});

it('draws only the project it was asked about', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $other = Project::factory()->in($workspace)->create();

    due($workspace, $project, '2026-07-07 09:00', 'Ours');
    due($workspace, $other, '2026-07-07 09:00', 'Theirs');

    expect(array_column(day(calendarOf($project, $actor), '2026-07-07')['tasks'], 'title'))->toBe(['Ours']);
});

it('narrows the grid and the tray by tag', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $tag = Tag::factory()->in($workspace)->create();

    $tagged = due($workspace, $project, '2026-07-07 09:00', 'Tagged');
    due($workspace, $project, '2026-07-07 10:00', 'Untagged');

    $undatedTagged = unscheduled($workspace, $project, 'Tagged and undated');
    unscheduled($workspace, $project, 'Untagged and undated');

    $tagged->task->tags()->attach($tag);
    $undatedTagged->task->tags()->attach($tag);

    $calendar = calendarOf($project, $actor, '2026-07', [$tag->id]);
    $cell = day($calendar, '2026-07-07');

    /** @var array{count: int, tasks: list<array<string, mixed>>} $undated */
    $undated = $calendar['undated'];

    expect(array_column($cell['tasks'], 'title'))->toBe(['Tagged'])
        ->and($cell['count'])->toBe(1)
        ->and(array_column($undated['tasks'], 'title'))->toBe(['Tagged and undated'])
        ->and($undated['count'])->toBe(1);
});

it('reads a month in a bounded number of queries', function (): void {
    [$workspace, $project, $actor] = placeableProject();

    foreach (range(1, 20) as $slot) {
        due($workspace, $project, sprintf('2026-07-%02d 09:00', ($slot % 28) + 1), "Card {$slot}");
    }

    foreach (range(1, 5) as $slot) {
        unscheduled($workspace, $project, "Someday {$slot}");
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $calendar = calendarOf($project, $actor);

    /*
     * The counts, the page of ids, the cards, their tasks, those tasks' assignees and tags, the
     * tray and its count and tasks and their two relations, plus the memberships the permissions
     * ask for. A bound rather than an exact number, because those lookups are memoised per
     * request (TASK-040-020) and a month with no cards asks fewer.
     */
    expect(count($queries))->toBeLessThanOrEqual(16)
        ->and($calendar['undated']['count'])->toBe(5);
});

it('answers the three permissions the screen renders', function (): void {
    [, $project, $actor] = placeableProject();

    /** @var array{createTask: bool, updateTask: bool, deleteTask: bool} $can */
    $can = calendarOf($project, $actor)['can'];

    expect($can['createTask'])->toBeTrue()
        ->and($can['updateTask'])->toBeTrue()
        ->and($can['deleteTask'])->toBeTrue();
});
