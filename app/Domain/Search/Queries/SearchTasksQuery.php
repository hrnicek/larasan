<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Queries\ChangeableProjectsForUser;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\ReachableTasks;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class SearchTasksQuery
{
    public const PER_PAGE = 25;

    public const CANDIDATES = 500;

    public function __construct(
        private ReachableTasks $reachable,
        private ChangeableProjectsForUser $changeableProjects,
    ) {}

    /**
     * @param  array{project?: string, assignee?: int, completed?: bool}  $filters
     * @return array{
     *     tasks: list<array<string, mixed>>,
     *     meta: array{term: string, page: int, perPage: int, total: int, hasMore: bool, degraded: bool, capped: bool},
     * }
     */
    public function __invoke(
        Workspace $workspace,
        User $actor,
        string $term,
        int $page = 1,
        array $filters = [],
        int $perPage = self::PER_PAGE,
    ): array {
        $query = $this->tsquery($term);

        if ($query === null) {
            return $this->empty($term, $page, $perPage);
        }

        $matches = $this->matches($workspace, $term);

        $results = $this->paginate($workspace, $actor, $query, $matches, $filters, $page, $perPage);

        $mayUpdate = $workspace->membershipFor($actor)?->allows(Capability::TaskUpdate) === true;
        $people = PersonSummary::for($workspace, $actor);

        return [
            'tasks' => array_values($results->getCollection()
                ->map(fn (Task $task): array => $this->row($task, $mayUpdate && $this->onAChangeableBoard($task), $people))
                ->all()),
            'meta' => [
                'term' => $term,
                'page' => $results->currentPage(),
                'perPage' => $results->perPage(),
                'total' => $results->total(),
                'hasMore' => $results->hasMorePages(),
                'degraded' => $matches === null,
                'capped' => $matches !== null && count($matches) >= self::CANDIDATES,
            ],
        ];
    }

    /**
     * Keys only: the engine ranks, PostgreSQL authorizes. See ADR-0016.
     *
     * @return list<string>|null
     */
    private function matches(Workspace $workspace, string $term): ?array
    {
        // The `collection` and `null` Scout drivers do not match like Meilisearch, so they use the PostgreSQL path.
        if (config('scout.driver') !== 'meilisearch') {
            return null;
        }

        try {
            return array_values(Task::search($term)
                ->where('workspace_id', $workspace->id)
                ->take(self::CANDIDATES)
                ->keys()
                ->map(fn (mixed $key): string => (string) $key)
                ->all());
        } catch (Throwable $failure) {
            Log::warning('The search screen fell back to PostgreSQL.', ['exception' => $failure->getMessage()]);

            return null;
        }
    }

    /**
     * Punctuation is stripped because characters such as `&`, `!` and quotes are `to_tsquery` operators.
     */
    private function tsquery(string $term): ?string
    {
        $words = array_values(array_filter(
            preg_split('/\s+/u', Str::lower(trim($term))) ?: [],
            fn (string $word): bool => $word !== '',
        ));

        $words = array_values(array_filter(array_map(
            fn (string $word): string => (string) preg_replace('/[^\p{L}\p{N}_]+/u', '', $word),
            $words,
        ), fn (string $word): bool => $word !== ''));

        if ($words === []) {
            return null;
        }

        $last = array_key_last($words);
        $words[$last] .= ':*';

        return implode(' & ', $words);
    }

    /**
     * @param  list<string>|null  $matches
     * @param  array{project?: string, assignee?: int, completed?: bool}  $filters
     * @return LengthAwarePaginator<int, Task>
     */
    private function paginate(
        Workspace $workspace,
        User $actor,
        string $query,
        ?array $matches,
        array $filters,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        $visible = $this->reachable->projectIds($workspace, $actor);

        $tasks = $this->reachable
            ->constrain(Task::query(), $workspace, $actor)
            ->select(['id', 'workspace_id', 'title', 'due_at', 'priority', 'completed_at', 'assignee_id'])
            ->withCount('comments')
            ->withCount('placements')
            ->withExists(['placements as on_a_changeable_board' => fn (Builder $placements): Builder => $placements
                ->whereIn('project_id', $this->changeableProjects
                    ->query($workspace, $actor, Capability::TaskUpdate)
                    ->select('projects.id'))])
            ->with([
                PersonSummary::eager('assignee'),
                'tags:id,name,color',
                'placements' => fn (Relation $placements) => $placements
                    ->whereIn('project_id', $visible)
                    ->with('project:id,name,color'),
            ])
            ->orderByDesc('id');

        if ($matches === null) {
            $tasks
                ->whereRaw("search_vector @@ to_tsquery('simple', immutable_unaccent(?))", [$query])
                ->reorder()
                ->orderByRaw("ts_rank_cd(search_vector, to_tsquery('simple', immutable_unaccent(?))) desc", [$query])
                ->orderByDesc('id');
        } else {
            // `array_position` restores the engine's ranking order.
            $tasks
                ->whereIn('tasks.id', $matches)
                ->reorder()
                ->orderByRaw('array_position(?::uuid[], tasks.id)', ['{'.implode(',', $matches).'}'])
                ->orderByDesc('id');
        }

        $this->applyFilters($tasks, $filters);

        return $tasks->paginate(perPage: $perPage, page: $page);
    }

    /**
     * @param  Builder<Task>  $tasks
     * @param  array{project?: string, assignee?: int, completed?: bool}  $filters
     */
    private function applyFilters(Builder $tasks, array $filters): void
    {
        if (isset($filters['project'])) {
            $tasks->whereHas(
                'placements',
                fn (Builder $placements): Builder => $placements->where('project_id', $filters['project']),
            );
        }

        if (isset($filters['assignee'])) {
            $tasks->where('assignee_id', $filters['assignee']);
        }

        if (isset($filters['completed'])) {
            $filters['completed']
                ? $tasks->whereNotNull('completed_at')
                : $tasks->whereNull('completed_at');
        }
    }

    /**
     * @return array{tasks: list<array<string, mixed>>, meta: array{term: string, page: int, perPage: int, total: int, hasMore: bool, degraded: bool, capped: bool}}
     */
    private function empty(string $term, int $page, int $perPage): array
    {
        return [
            'tasks' => [],
            'meta' => [
                'term' => $term,
                'page' => $page,
                'perPage' => $perPage,
                'total' => 0,
                'hasMore' => false,
                'degraded' => false,
                'capped' => false,
            ],
        ];
    }

    /**
     * A task in no project is workspace-level work and counts as changeable.
     */
    private function onAChangeableBoard(Task $task): bool
    {
        return (int) ($task->placements_count ?? 0) === 0
            || (bool) ($task->on_a_changeable_board ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Task $task, bool $canUpdate, PersonSummary $people): array
    {
        $assignee = $task->assignee;

        return [
            'id' => $task->id,
            'canUpdate' => $canUpdate,
            'title' => $task->title,
            'dueAt' => $task->due_at?->toIso8601String(),
            'completedAt' => $task->completed_at?->toIso8601String(),
            'priority' => $task->priority->value,
            'comments' => (int) ($task->comments_count ?? 0),
            'assignee' => $people->ofNullable($assignee),
            'tags' => array_values($task->tags
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color?->value,
                ])
                ->all()),
            'projects' => array_values($task->placements
                ->map(fn (TaskProjectMembership $placement): array => [
                    'id' => $placement->project->id,
                    'name' => $placement->project->name,
                    'color' => $placement->project->color?->value,
                ])
                ->all()),
        ];
    }
}
