<?php

declare(strict_types=1);

namespace App\Domain\Project\Queries;

use App\Domain\File\Models\Attachment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection as Grouped;
use Illuminate\Support\Facades\DB;

final readonly class ProjectBoardQuery
{
    public const PER_COLUMN = 25;

    public const UNGROUPED = 'ungrouped';

    /**
     * @param  list<string>  $expanded  columns the reader has asked to see in full
     * @param  list<string>  $tags  every one of which a card must carry
     * @return array{
     *     columns: list<array{id: string|null, name: string|null, color: string|null, count: int, hasMore: bool, tasks: list<array<string, mixed>>}>,
     *     perColumn: int,
     *     can: array{createTask: bool, updateTask: bool, deleteTask: bool, createSection: bool, updateSection: bool, deleteSection: bool},
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
                'createSection' => $project->allowsChangesBy($actor, Capability::SectionCreate),
                'updateSection' => $project->allowsChangesBy($actor, Capability::SectionUpdate),
                'deleteSection' => $project->allowsChangesBy($actor, Capability::SectionDelete),
            ],
        ];
    }

    /**
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
                    ->with([PersonSummary::eager('assignee'), 'tags:id,name,color']);
            }])
            ->orderBy('position')
            ->get()
            ->groupBy(fn (TaskProjectMembership $card): string => $card->section_id ?? self::UNGROUPED);
    }

    /**
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
     * Uses PostgreSQL DISTINCT ON to take each task's first image by position in one query.
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
                    // Joins bypass SoftDeletes; the workspace is asserted on the file itself.
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
            'cover' => $covers[$task->id] ?? null,
            'assignee' => PersonSummary::fromNullable($assignee),
        ];
    }
}
