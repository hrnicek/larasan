<?php

declare(strict_types=1);

namespace App\Domain\Project\Queries;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection as Grouped;
use Illuminate\Support\Facades\DB;

/**
 * The board: the same placements the list reads, drawn as columns, and bounded.
 *
 * The one thing this does that `ProjectListQuery` does not is **stop**. A column ships a page
 * of cards and the total it was drawn from, so a project with four thousand cards renders in
 * the same time as one with forty and "load more" is a partial reload rather than a second
 * full read. The page is cut inside the database with a window function, not by reading
 * everything and slicing in PHP — a query that reads the whole board to show a hundred cards
 * has not been bounded, it has been hidden.
 *
 * Everything else it inherits deliberately: the ungrouped bucket is a column (ADR-0004), the
 * count and the rows come from one scope so a header cannot disagree with them
 * (TASK-050-013), and authorization is answered once for the project rather than per card
 * (TASK-070-015).
 */
final readonly class ProjectBoardQuery
{
    /** Enough to fill a tall column twice over, and small enough that a wide board is cheap. */
    public const PER_COLUMN = 25;

    /** The key the ungrouped bucket answers to, since it has no id of its own. */
    public const UNGROUPED = 'ungrouped';

    /**
     * @param  list<string>  $expanded  columns the reader has asked to see in full
     * @return array{
     *     columns: list<array{id: string|null, name: string|null, color: string|null, count: int, hasMore: bool, tasks: list<array<string, mixed>>}>,
     *     perColumn: int,
     *     can: array{createTask: bool, updateTask: bool, deleteTask: bool},
     * }
     */
    public function __invoke(Project $project, User $actor, array $expanded = []): array
    {
        $counts = $this->counts($project);
        $cards = $this->cards($project, $expanded);

        $columns = $project->sections()->get()
            ->map(fn (Section $section): array => $this->column(
                $section->id,
                $section->name,
                $section->color?->value,
                $cards->get($section->id) ?? new Collection,
                $counts[$section->id] ?? 0,
                in_array($section->id, $expanded, strict: true),
            ))
            ->all();

        $ungroupedCount = $counts[self::UNGROUPED] ?? 0;

        // Only when it holds something: an empty "no column" on every board is noise, and a
        // non-empty one is work somebody has to be able to see.
        if ($ungroupedCount > 0) {
            $columns[] = $this->column(
                null,
                null,
                null,
                $cards->get(self::UNGROUPED) ?? new Collection,
                $ungroupedCount,
                in_array(self::UNGROUPED, $expanded, strict: true),
            );
        }

        return [
            'columns' => array_values($columns),
            'perColumn' => self::PER_COLUMN,
            'can' => [
                'createTask' => $actor->can('createTask', $project),
                'updateTask' => $project->allowsChangesBy($actor, Capability::TaskUpdate),
                'deleteTask' => $project->allowsChangesBy($actor, Capability::TaskDelete),
            ],
        ];
    }

    /**
     * How many visible cards each column holds, whatever the page shows.
     *
     * @return array<string, int>
     */
    private function counts(Project $project): array
    {
        /** @var Grouped<int, object{section: string|null, total: int}> $rows */
        $rows = TaskProjectMembership::query()
            ->visible()
            ->where('project_id', $project->id)
            ->toBase()
            ->selectRaw('section_id as section, count(*) as total')
            ->groupBy('section_id')
            ->get();

        return $rows
            ->mapWithKeys(fn (object $row): array => [$row->section ?? self::UNGROUPED => (int) $row->total])
            ->all();
    }

    /**
     * One page of cards per column, cut in the database.
     *
     * `row_number() over (partition by section_id order by position)` numbers each column's
     * cards independently, so one query returns the first page of every column at once. A
     * column the reader has expanded is read separately and in full — that is one column, on
     * purpose, rather than a board-wide limit somebody can turn off.
     *
     * @param  list<string>  $expanded
     * @return Grouped<string, Collection<int, TaskProjectMembership>>
     */
    private function cards(Project $project, array $expanded): Grouped
    {
        $paged = TaskProjectMembership::query()
            ->visible()
            ->where('project_id', $project->id)
            ->toBase()
            ->selectRaw('id, row_number() over (partition by section_id order by position) as rank')
            ->orderBy('position');

        $ids = DB::query()
            ->fromSub($paged, 'ranked')
            ->where('rank', '<=', self::PER_COLUMN)
            ->pluck('id')
            ->all();

        $query = TaskProjectMembership::query()
            ->visible()
            ->where('project_id', $project->id)
            ->where(function (Builder $rows) use ($ids, $expanded): void {
                $rows->whereIn('id', $ids);

                if ($expanded === []) {
                    return;
                }

                $rows->orWhere(function (Builder $column) use ($expanded): void {
                    $this->whereExpanded($column, $expanded);
                });
            });

        return $query
            ->with(['task' => function (Relation $tasks): void {
                $tasks
                    ->select(['id', 'workspace_id', 'title', 'completed_at', 'due_at', 'priority', 'assignee_id'])
                    ->withCount('children')
                    ->with('assignee:id,name,email');
            }])
            ->orderBy('position')
            ->get()
            ->groupBy(fn (TaskProjectMembership $card): string => $card->section_id ?? self::UNGROUPED);
    }

    /**
     * The expanded columns, with the ungrouped bucket spelled as the null it really is.
     *
     * @param  list<string>  $expanded
     */
    private function whereExpanded(Builder $query, array $expanded): void
    {
        $sections = array_values(array_filter($expanded, fn (string $id): bool => $id !== self::UNGROUPED));
        $ungrouped = in_array(self::UNGROUPED, $expanded, strict: true);

        if ($sections !== []) {
            $query->whereIn('section_id', $sections);
        }

        if (! $ungrouped) {
            return;
        }

        if ($sections === []) {
            $query->whereNull('section_id');

            return;
        }

        $query->orWhereNull('section_id');
    }

    /**
     * @param  Collection<int, TaskProjectMembership>  $cards
     * @return array{id: string|null, name: string|null, color: string|null, count: int, hasMore: bool, tasks: list<array<string, mixed>>}
     */
    private function column(?string $id, ?string $name, ?string $color, Collection $cards, int $count, bool $expanded): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'color' => $color,
            'count' => $count,
            // What the column knows it is not showing, so "load more" is a fact rather than a
            // guess the client makes from a page size.
            'hasMore' => ! $expanded && $count > $cards->count(),
            'tasks' => array_values($cards->map(fn (TaskProjectMembership $card): array => $this->card($card))->all()),
        ];
    }

    /**
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
            'subtasks' => (int) ($task->children_count ?? 0),
            'assignee' => $assignee === null ? null : [
                'id' => $assignee->id,
                'name' => $assignee->name,
                'email' => $assignee->email,
                'avatar' => null,
            ],
        ];
    }
}
