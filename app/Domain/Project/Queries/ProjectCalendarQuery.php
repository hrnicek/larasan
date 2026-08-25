<?php

declare(strict_types=1);

namespace App\Domain\Project\Queries;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection as Grouped;
use Illuminate\Support\Facades\DB;

/**
 * A month of the project, by due date: the calendar view's whole data source.
 *
 * Three things it settles, all of them for the same reason the board settled them:
 *
 * - **the grid is whole weeks**, Monday first, so the month is drawn without half a row at
 *   either end. The days that spill in from the neighbouring months carry their tasks — a card
 *   due on the 31st is not hidden because the reader is looking at August.
 * - **a day is bounded.** A page of cards per day and the total it was cut from, so a project
 *   that puts two hundred tasks on one deadline renders like any other. The cut happens in the
 *   database (`row_number() over (partition by …)`), because reading a month in full and
 *   slicing it in PHP is not a bound.
 * - **a task with no due date is not missing**, it is undated. The same reasoning as the
 *   ungrouped bucket (ADR-0004): work that has not been scheduled is still work, so it gets a
 *   count and a tray of its own rather than falling out of the view.
 *
 * Days are grouped by the stored instant's date, unconverted. That is the same day the pickers
 * read back out of `dueAt` (`DueDatePicker` slices the ISO string), so what is stored, what the
 * grid draws and what the picker shows are one answer rather than three.
 */
final readonly class ProjectCalendarQuery
{
    /** As many as a cell can show before it becomes a list nobody reads. */
    public const PER_DAY = 8;

    /** The tray is a shortcut for scheduling, not a second list view. */
    public const UNDATED = 50;

    /**
     * @param  list<string>  $tags  every one of which a card must carry
     * @return array{
     *     month: string,
     *     today: string,
     *     perDay: int,
     *     days: list<array{date: string, inMonth: bool, count: int, hasMore: bool, tasks: list<array<string, mixed>>}>,
     *     undated: array{count: int, hasMore: bool, tasks: list<array<string, mixed>>},
     *     can: array{createTask: bool, updateTask: bool, deleteTask: bool},
     * }
     */
    public function __invoke(Project $project, User $actor, CarbonImmutable $month, array $tags = []): array
    {
        $first = $month->startOfMonth();
        $start = $first->startOfWeek(CarbonImmutable::MONDAY);
        $end = $month->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        $counts = $this->counts($project, $tags, $start, $end);
        $cards = $this->cards($project, $tags, $start, $end);

        $days = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $held = $cards->get($date) ?? new Collection;
            $count = $counts[$date] ?? 0;

            $days[] = [
                'date' => $date,
                'inMonth' => $day->month === $first->month,
                // The count is every card due that day and the page is what the cell draws, so
                // a cell that says "+3 more" is stating a fact rather than guessing from a size.
                'count' => $count,
                'hasMore' => $count > $held->count(),
                'tasks' => array_values($held->map(fn (TaskProjectMembership $card): array => $this->card($card))->all()),
            ];
        }

        $undated = $this->undated($project, $tags);
        $undatedCount = $this->undatedQuery($project, $tags)->count();

        return [
            'month' => $first->format('Y-m'),
            /*
             * The server's today, so the cell the grid rings and the day the *Today* control
             * returns to are the same day. Both are read out of one value rather than each
             * asking a different clock.
             */
            'today' => CarbonImmutable::now()->toDateString(),
            'perDay' => self::PER_DAY,
            'days' => $days,
            'undated' => [
                'count' => $undatedCount,
                'hasMore' => $undatedCount > $undated->count(),
                'tasks' => array_values($undated->map(fn (TaskProjectMembership $card): array => $this->card($card))->all()),
            ],
            /*
             * The permissions the screen renders, answered once for the project rather than per
             * card (TASK-070-015). Scheduling a task is `task.update` — dragging a card onto a
             * day is a due date being changed, and nothing else.
             */
            'can' => [
                'createTask' => $actor->can('createTask', $project),
                'updateTask' => $project->allowsChangesBy($actor, Capability::TaskUpdate),
                'deleteTask' => $project->allowsChangesBy($actor, Capability::TaskDelete),
            ],
        ];
    }

    /**
     * How many visible cards each day holds, whatever its page shows.
     *
     * @param  list<string>  $tags
     * @return array<string, int>
     */
    private function counts(Project $project, array $tags, CarbonImmutable $start, CarbonImmutable $end): array
    {
        /** @var Grouped<int, object{day: string, total: int}> $rows */
        $rows = $this->inRange($project, $tags, $start, $end)
            ->join('tasks', 'tasks.id', '=', 'task_project_memberships.task_id')
            ->toBase()
            ->selectRaw('to_char(tasks.due_at, \'YYYY-MM-DD\') as day, count(*) as total')
            ->groupBy('day')
            ->get();

        return $rows
            ->mapWithKeys(fn (object $row): array => [$row->day => (int) $row->total])
            ->all();
    }

    /**
     * One page of cards per day, cut in the database and keyed by the day it belongs to.
     *
     * @param  list<string>  $tags
     * @return Grouped<string, Collection<int, TaskProjectMembership>>
     */
    private function cards(Project $project, array $tags, CarbonImmutable $start, CarbonImmutable $end): Grouped
    {
        $ranked = $this->inRange($project, $tags, $start, $end)
            ->join('tasks', 'tasks.id', '=', 'task_project_memberships.task_id')
            ->toBase()
            ->selectRaw(
                'task_project_memberships.id, row_number() over ('
                .'partition by to_char(tasks.due_at, \'YYYY-MM-DD\') '
                .'order by tasks.due_at, task_project_memberships.position'
                .') as rank',
            );

        $ids = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rank', '<=', self::PER_DAY)
            ->pluck('id')
            ->all();

        $page = TaskProjectMembership::query()
            ->whereIn('task_project_memberships.id', $ids)
            // Ordered by the same two columns the page was cut on, so a cell draws its page in
            // the order the database chose it rather than in whatever order the ids came back.
            ->join('tasks', 'tasks.id', '=', 'task_project_memberships.task_id')
            ->select('task_project_memberships.*')
            ->orderBy('tasks.due_at')
            ->orderBy('task_project_memberships.position');

        return $this->withCardRelations($page)
            ->get()
            ->groupBy(fn (TaskProjectMembership $card): string => $card->task?->due_at?->toDateString() ?? '');
    }

    /**
     * The tasks in this project that nobody has scheduled, in the order the project holds them.
     *
     * @param  list<string>  $tags
     * @return Collection<int, TaskProjectMembership>
     */
    private function undated(Project $project, array $tags): Collection
    {
        return $this->withCardRelations($this->undatedQuery($project, $tags))
            ->orderBy('position')
            ->limit(self::UNDATED)
            ->get();
    }

    /**
     * @param  list<string>  $tags
     * @return Builder<TaskProjectMembership>
     */
    private function undatedQuery(Project $project, array $tags): Builder
    {
        return TaskProjectMembership::query()
            ->visible()
            ->taggedWithAll($tags)
            ->where('project_id', $project->id)
            ->whereHas('task', fn (Builder $tasks): Builder => $tasks->whereNull('due_at'));
    }

    /**
     * The project's visible cards whose task is due inside the drawn grid.
     *
     * @param  list<string>  $tags
     * @return Builder<TaskProjectMembership>
     */
    private function inRange(Project $project, array $tags, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return TaskProjectMembership::query()
            ->visible()
            ->taggedWithAll($tags)
            ->where('task_project_memberships.project_id', $project->id)
            ->whereHas('task', fn (Builder $tasks): Builder => $tasks
                ->whereNotNull('due_at')
                ->whereBetween('due_at', [$start->startOfDay(), $end->endOfDay()]));
    }

    /**
     * What a chip draws, in one read for the page rather than one per card.
     *
     * @param  Builder<TaskProjectMembership>  $query
     * @return Builder<TaskProjectMembership>
     */
    private function withCardRelations(Builder $query): Builder
    {
        return $query->with(['task' => function (Relation $tasks): void {
            $tasks
                ->select(['id', 'workspace_id', 'title', 'completed_at', 'due_at', 'priority', 'assignee_id'])
                ->with(['assignee:id,name,email', 'tags:id,name,color']);
        }]);
    }

    /**
     * Listed column by column rather than handed the model, for the reason the other two views
     * list theirs: a model would ship every column the table grows later as a public API.
     *
     * @return array<string, mixed>
     */
    private function card(TaskProjectMembership $card): array
    {
        /** @var Task $task */
        $task = $card->task;
        $assignee = $task->assignee;

        return [
            'placementId' => $card->id,
            'id' => $task->id,
            'title' => $task->title,
            'completedAt' => $task->completed_at?->toIso8601String(),
            'dueAt' => $task->due_at?->toIso8601String(),
            'priority' => $task->priority->value,
            'tags' => array_values($task->tags
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color?->value,
                ])
                ->all()),
            'assignee' => $assignee === null ? null : [
                'id' => $assignee->id,
                'name' => $assignee->name,
                'email' => $assignee->email,
                'avatar' => null,
            ],
        ];
    }
}
