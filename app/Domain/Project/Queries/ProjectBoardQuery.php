<?php

declare(strict_types=1);

namespace App\Domain\Project\Queries;

use App\Domain\File\Models\Attachment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;
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
     * @param  list<string>  $tags  every one of which a card must carry
     * @return array{
     *     columns: list<array{id: string|null, name: string|null, color: string|null, count: int, hasMore: bool, tasks: list<array<string, mixed>>}>,
     *     perColumn: int,
     *     can: array{createTask: bool, updateTask: bool, deleteTask: bool},
     * }
     */
    public function __invoke(Project $project, User $actor, array $expanded = [], array $tags = []): array
    {
        $counts = $this->counts($project, $tags);
        $cards = $this->cards($project, $expanded, $tags);
        $covers = $this->covers($project, $cards);

        $columns = $project->sections()->get()
            ->map(fn (Section $section): array => $this->column(
                $section->id,
                $section->name,
                $section->color?->value,
                $cards->get($section->id) ?? new Collection,
                $counts[$section->id] ?? 0,
                in_array($section->id, $expanded, strict: true),
                $covers,
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
                $covers,
            );
        }

        return [
            'columns' => array_values($columns),
            'perColumn' => self::PER_COLUMN,
            'can' => [
                'createTask' => $actor->can('createTask', $project),
                'updateTask' => $project->allowsChangesBy($actor, Capability::TaskUpdate),
                'deleteTask' => $project->allowsChangesBy($actor, Capability::TaskDelete),
                // The board's columns are the list's sections, so the two views answer the same
                // question the same way — which `BoardMatrixTest` asserts row by row.
                'createSection' => $project->allowsChangesBy($actor, Capability::SectionCreate),
                'updateSection' => $project->allowsChangesBy($actor, Capability::SectionUpdate),
                'deleteSection' => $project->allowsChangesBy($actor, Capability::SectionDelete),
            ],
        ];
    }

    /**
     * How many visible cards each column holds, whatever the page shows.
     *
     * @param  list<string>  $tags
     * @return array<string, int>
     */
    private function counts(Project $project, array $tags): array
    {
        /** @var Grouped<int, object{section: string|null, total: int}> $rows */
        $rows = TaskProjectMembership::query()
            ->visible()
            ->taggedWithAll($tags)
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
     * @param  list<string>  $tags
     * @return Grouped<string, Collection<int, TaskProjectMembership>>
     */
    private function cards(Project $project, array $expanded, array $tags): Grouped
    {
        $paged = TaskProjectMembership::query()
            ->visible()
            ->taggedWithAll($tags)
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
            ->taggedWithAll($tags)
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
                    ->withCount(['children', 'comments'])
                    // The chips a card draws: one read for the page's tags, not one per card.
                    ->with(['assignee:id,name,email', 'tags:id,name,color']);
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
     * The first image attached to each task on this page, as one read.
     *
     * `DISTINCT ON` is PostgreSQL's answer to "the first row of each group" and it is the whole
     * reason this is one query rather than one per card — the N+1 TASK-070-015 took off this
     * board is not one to put back for a picture.
     *
     * "First" is `position`, which is somebody's decision (TASK-250-003), not the earliest
     * upload. The dimensions come from the file's metadata so the card can reserve the space
     * before the bytes arrive; they are absent until the thumbnail has been derived, which is a
     * fixed-ratio box rather than a broken one.
     *
     * @param  Grouped<string, Collection<int, TaskProjectMembership>>  $cards
     * @return array<string, array{id: string, width: int|null, height: int|null}>
     */
    private function covers(Project $project, Grouped $cards): array
    {
        $taskIds = $cards->flatten()->pluck('task_id')->unique()->values()->all();

        if ($taskIds === []) {
            return [];
        }

        $rows = Attachment::query()
            ->toBase()
            ->join('files', function (JoinClause $file) use ($project): void {
                $file->on('files.id', '=', 'attachments.file_id')
                    // A removed file takes its cover off the card, and the workspace is asserted
                    // in the query rather than assumed from the task (`docs/architecture/database.md`).
                    ->whereNull('files.deleted_at')
                    ->where('files.workspace_id', '=', $project->workspace_id);
            })
            ->where('attachments.attachable_type', (string) Relation::getMorphAlias(Task::class))
            ->whereIn('attachments.attachable_id', $taskIds)
            ->where('files.mime_type', 'like', 'image/%')
            ->orderBy('attachments.attachable_id')
            ->orderBy('attachments.position')
            ->select(DB::raw('distinct on (attachments.attachable_id) attachments.attachable_id, attachments.id, files.metadata'))
            ->get();

        $covers = [];

        foreach ($rows as $row) {
            $metadata = json_decode((string) $row->metadata, true);
            $width = is_array($metadata) ? (int) ($metadata['width'] ?? 0) : 0;
            $height = is_array($metadata) ? (int) ($metadata['height'] ?? 0) : 0;

            $covers[(string) $row->attachable_id] = [
                'id' => (string) $row->id,
                'width' => $width > 0 ? $width : null,
                'height' => $height > 0 ? $height : null,
            ];
        }

        return $covers;
    }

    /**
     * @param  Collection<int, TaskProjectMembership>  $cards
     * @param  array<string, array{id: string, width: int|null, height: int|null}>  $covers
     * @return array{id: string|null, name: string|null, color: string|null, count: int, hasMore: bool, tasks: list<array<string, mixed>>}
     */
    private function column(?string $id, ?string $name, ?string $color, Collection $cards, int $count, bool $expanded, array $covers): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'color' => $color,
            'count' => $count,
            // What the column knows it is not showing, so "load more" is a fact rather than a
            // guess the client makes from a page size.
            'hasMore' => ! $expanded && $count > $cards->count(),
            'tasks' => array_values($cards->map(fn (TaskProjectMembership $card): array => $this->card($card, $covers))->all()),
        ];
    }

    /**
     * @param  array<string, array{id: string, width: int|null, height: int|null}>  $covers
     * @return array<string, mixed>
     */
    private function card(TaskProjectMembership $card, array $covers): array
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
            'comments' => (int) ($task->comments_count ?? 0),
            'tags' => array_values($task->tags
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color?->value,
                ])
                ->all()),
            'subtasks' => (int) ($task->children_count ?? 0),
            // Only the board fills this in. The list, My Tasks and the calendar share
            // `TaskRowData` and are unchanged, which is why the field is optional there.
            'cover' => $covers[$task->id] ?? null,
            'assignee' => $assignee === null ? null : [
                'id' => $assignee->id,
                'name' => $assignee->name,
                'email' => $assignee->email,
                'avatar' => null,
            ],
        ];
    }
}
