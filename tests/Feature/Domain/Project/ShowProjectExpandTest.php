<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\ProjectBoardQuery;
use App\Domain\Project\Queries\ProjectCalendarQuery;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

function fillExpandableColumn(Project $project, ?Section $column, int $count): void
{
    foreach (range(1, $count) as $slot) {
        $factory = TaskProjectMembership::factory()
            ->placing(Task::factory()->in($project->workspace)->create(), $project)
            ->at($slot * SparsePosition::GAP);

        ($column === null ? $factory : $factory->inSection($column))->create();
    }
}

it('ignores an expanded value on the board that cannot name a column', function (string $value): void {
    [, $project, $actor] = placeableProject();
    $column = Section::factory()->in($project)->create();
    fillExpandableColumn($project, $column, ProjectBoardQuery::PER_COLUMN + 1);

    $this->actingAs($actor)
        ->get(route('projects.show', [
            'project' => $project,
            'view' => ProjectDefaultView::Board->value,
            'expand' => [$value, $column->id],
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('board.columns.0.tasks', ProjectBoardQuery::PER_COLUMN + 1));
})->with([
    'a calendar day' => ['2026-09-01'],
    'free text' => ['not-a-column'],
]);

it('still expands the ungrouped column on the board', function (): void {
    [, $project, $actor] = placeableProject();
    fillExpandableColumn($project, null, ProjectBoardQuery::PER_COLUMN + 1);

    $this->actingAs($actor)
        ->get(route('projects.show', [
            'project' => $project,
            'view' => ProjectDefaultView::Board->value,
            'expand' => [ProjectBoardQuery::UNGROUPED],
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('board.columns.0.tasks', ProjectBoardQuery::PER_COLUMN + 1));
});

it('still expands a day on the calendar', function (): void {
    [$workspace, $project, $actor] = placeableProject();

    foreach (range(1, ProjectCalendarQuery::PER_DAY + 1) as $slot) {
        attach(Task::factory()->in($workspace)->dueAt(CarbonImmutable::parse('2026-07-07 09:00'))->create(), $project, $actor);
    }

    $this->actingAs($actor)
        ->get(route('projects.show', [
            'project' => $project,
            'view' => ProjectDefaultView::Calendar->value,
            'month' => '2026-07',
            'expand' => ['2026-07-07'],
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('calendar.days.8.date', '2026-07-07')
            ->has('calendar.days.8.tasks', ProjectCalendarQuery::PER_DAY + 1)
            ->where('calendar.days.8.hasMore', false));
});
