<?php

declare(strict_types=1);

namespace App\Domain\Project\Queries;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Payloads\PersonSummary;
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
 * Days are grouped by the stored instant's date without timezone conversion,
 * matching how DueDatePicker reads dueAt.
 */
final readonly class ProjectCalendarQuery
{
    public const PER_DAY = 4;

    public const UNDATED = 50;

    /**
     * @param  list<string>  $tags  every one of which a card must carry
     * @param  list<string>  $expanded  days the reader has asked to see in full, as `Y-m-d`
     * @return array{
     *     month: string,
     *     today: string,
     *     perDay: int,
     *     days: list<array{date: string, inMonth: bool, count: int, hasMore: bool, tasks: list<array<string, mixed>>}>,
     *     undated: array{count: int, hasMore: bool, tasks: list<array<string, mixed>>},
     *     can: array{createTask: bool, updateTask: bool, deleteTask: bool},
     * }
     */
    public function __invoke(
        Project $project,
        User $actor,
        CarbonImmutable $month,
        array $tags = [],
        array $expanded = [],
    ): array {
        $first = $month->startOfMonth();
        $start = $first->startOfWeek(CarbonImmutable::MONDAY);
        $end = $month->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        $counts = $this->counts($project, $tags, $start, $end);
        $cards = $this->cards($project, $tags, $start, $end, $expanded);
        $people = PersonSummary::for($project->workspace, $actor);

        $days = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $held = $cards->get($date) ?? new Collection;
            $count = $counts[$date] ?? 0;

            $days[] = [
                'date' => $date,
                'inMonth' => $day->month === $first->month,
                'count' => $count,
                'hasMore' => $count > $held->count(),
                'tasks' => array_values($held->map(fn (TaskProjectMembership $card): array => $this->card($card, $people))->all()),
            ];
        }

        $undated = $this->undated($project, $tags);
        $undatedCount = $this->undatedQuery($project, $tags)->count();

        return [
            'month' => $first->format('Y-m'),
            'today' => CarbonImmutable::now()->toDateString(),
            'perDay' => self::PER_DAY,
            'days' => $days,
            'undated' => [
                'count' => $undatedCount,
                'hasMore' => $undatedCount > $undated->count(),
                'tasks' => array_values($undated->map(fn (TaskProjectMembership $card): array => $this->card($card, $people))->all()),
            ],
            'can' => [
                'createTask' => $actor->can('createTask', $project),
                'updateTask' => $project->allowsChangesBy($actor, Capability::TaskUpdate),
                'deleteTask' => $project->allowsChangesBy($actor, Capability::TaskDelete),
            ],
        ];
    }

    /**
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
     * @param  list<string>  $tags
     * @param  list<string>  $expanded
     * @return Grouped<string, Collection<int, TaskProjectMembership>>
     */
    private function cards(
        Project $project,
        array $tags,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $expanded,
    ): Grouped {
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
            ->visible()
            ->taggedWithAll($tags)
            ->where('task_project_memberships.project_id', $project->id)
            ->where(function (Builder $rows) use ($ids, $expanded): void {
                $rows->whereIn('task_project_memberships.id', $ids);

                foreach ($expanded as $day) {
                    $opened = CarbonImmutable::parse($day);

                    $rows->orWhereBetween('tasks.due_at', [$opened->startOfDay(), $opened->endOfDay()]);
                }
            })
            ->join('tasks', 'tasks.id', '=', 'task_project_memberships.task_id')
            ->select('task_project_memberships.*')
            ->orderBy('tasks.due_at')
            ->orderBy('task_project_memberships.position');

        return $this->withCardRelations($page)
            ->get()
            ->groupBy(fn (TaskProjectMembership $card): string => $card->task?->due_at?->toDateString() ?? '');
    }

    /**
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
     * @param  Builder<TaskProjectMembership>  $query
     * @return Builder<TaskProjectMembership>
     */
    private function withCardRelations(Builder $query): Builder
    {
        return $query->with(['task' => function (Relation $tasks): void {
            $tasks
                ->select(['id', 'workspace_id', 'title', 'completed_at', 'due_at', 'priority', 'assignee_id'])
                ->with([PersonSummary::eager('assignee'), 'tags:id,name,color']);
        }]);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(TaskProjectMembership $card, PersonSummary $people): array
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
            'assignee' => $people->ofNullable($assignee),
        ];
    }
}
