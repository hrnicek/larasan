<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Queries\ChangeableProjectsForUser;
use App\Domain\Shared\Enums\Capability;
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

/**
 * Finding work, without finding work somebody may not see.
 *
 * Search is the one screen where a leak is invisible: a list nobody expected to be complete is a
 * list nobody notices a missing row in, and a row that should not be there looks like a feature.
 * So reach is **part of the query** rather than a filter applied to its results — the same
 * arithmetic `TaskPolicy::view()` states, written once here (ADR-0006, TASK-070-017).
 *
 * The matching is ADR-0012's: a `simple`, unaccented `tsvector` on the task, with the term's
 * last word treated as a prefix so `log` finds `login`.
 */
final readonly class SearchTasksQuery
{
    public const PER_PAGE = 25;

    /**
     * How many keys the engine is asked for.
     *
     * The screen pages against PostgreSQL, so the total it shows is exact — for the matches it
     * was given. A cap is unavoidable (the alternative is asking an engine for every match of
     * "a"), and `meta.capped` is how the screen says it was reached.
     */
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
            /*
             * An empty term returns nothing rather than everything. "Everything" is the one
             * answer nobody typed a search box to get, and on a workspace of any size it is also
             * the most expensive.
             */
            return $this->empty($term, $page, $perPage);
        }

        $matches = $this->matches($workspace, $term);

        $results = $this->paginate($workspace, $actor, $query, $matches, $filters, $page, $perPage);

        // Asked once for the page rather than per row: the capability is the actor's, and the
        // boards are a subquery the rows were already counted against.
        $mayUpdate = $workspace->membershipFor($actor)?->allows(Capability::TaskUpdate) === true;

        return [
            'tasks' => array_values($results->getCollection()
                ->map(fn (Task $task): array => $this->row($task, $mayUpdate && $this->onAChangeableBoard($task)))
                ->all()),
            'meta' => [
                'term' => $term,
                'page' => $results->currentPage(),
                'perPage' => $results->perPage(),
                'total' => $results->total(),
                'hasMore' => $results->hasMorePages(),
                /*
                 * True when the engine could not be reached and this answer came from the
                 * generated column instead: narrower, no typo tolerance, and the screen says so
                 * rather than looking quietly worse (ADR-0016).
                 */
                'degraded' => $matches === null,
                /*
                 * Whether the engine had more matches than it was asked for. The count below is
                 * exact for what came back, and this is what keeps "1 of 500" from reading as
                 * "everything there is".
                 */
                'capped' => $matches !== null && count($matches) >= self::CANDIDATES,
            ],
        ];
    }

    /**
     * The keys Meilisearch ranks for this term, or null when it cannot be reached.
     *
     * Ids rather than rows: what may be *seen* is decided by the query below, in PostgreSQL,
     * against the same reach rule every other list uses. The engine matches and orders; it never
     * authorizes (ADR-0016).
     *
     * @return list<string>|null
     */
    private function matches(Workspace $workspace, string $term): ?array
    {
        /*
         * Meilisearch is the engine this application has (ADR-0016). The other Scout drivers are
         * not one for this screen's purposes: `collection` matches substrings in memory and
         * `null` matches nothing, and either would quietly answer a page of results with
         * semantics no ADR describes. Without an engine, the generated column is the answer.
         */
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
     * The term as PostgreSQL wants it: words joined by AND, the last one a prefix.
     *
     * Built rather than passed through, because a raw term is somebody's typing — `&`, `!` and a
     * stray quote are all operators to `to_tsquery`, and a search box is not a place to learn
     * that. Returns null when nothing usable is left.
     */
    private function tsquery(string $term): ?string
    {
        $words = array_values(array_filter(
            preg_split('/\s+/u', Str::lower(trim($term))) ?: [],
            fn (string $word): bool => $word !== '',
        ));

        // Only what a word can be made of. Everything else is punctuation somebody typed, and
        // punctuation has no meaning here.
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

        // Reach is `ReachableTasks` and is not restated here: it is the same sentence the
        // engine-backed path applies in its hydration query (ADR-0016).
        $tasks = $this->reachable
            ->constrain(Task::query(), $workspace, $actor)
            ->select(['id', 'workspace_id', 'title', 'due_at', 'priority', 'completed_at', 'assignee_id'])
            ->withCount('comments')
            // The two the row's `canUpdate` is decided from, as subqueries (TASK-260-001).
            ->withCount('placements')
            ->withExists(['placements as on_a_changeable_board' => fn (Builder $placements): Builder => $placements
                ->whereIn('project_id', $this->changeableProjects
                    ->query($workspace, $actor, Capability::TaskUpdate)
                    ->select('projects.id'))])
            ->with([
                'assignee:id,name,email',
                'tags:id,name,color',
                'placements' => fn (Relation $placements) => $placements
                    ->whereIn('project_id', $visible)
                    ->with('project:id,name,color'),
            ])
            ->orderByDesc('id');

        if ($matches === null) {
            // The degraded path: the generated column and its GIN index, which is what this
            // screen ran on before there was an engine (ADR-0012).
            $tasks
                ->whereRaw("search_vector @@ to_tsquery('simple', immutable_unaccent(?))", [$query])
                // Rank first, then the key: `created_at` is `timestamp(0)` and ties are common,
                // so without a second key a page would reshuffle between requests.
                ->reorder()
                ->orderByRaw("ts_rank_cd(search_vector, to_tsquery('simple', immutable_unaccent(?))) desc", [$query])
                ->orderByDesc('id');
        } else {
            /*
             * The engine's own order, kept: `array_position` puts the rows back in the order
             * Meilisearch ranked them, which is the ordering the palette shows and the reason
             * a misspelt term finds anything at all.
             */
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
            // Two states rather than three: "finished" and "still open" are what people mean,
            // and the absence of the filter is "either".
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
     * Whether one of this task's boards is open to the actor — or it has none at all, which is
     * workspace work.
     */
    private function onAChangeableBoard(Task $task): bool
    {
        return (int) ($task->placements_count ?? 0) === 0
            || (bool) ($task->on_a_changeable_board ?? false);
    }

    /**
     * The row the other lists draw, so a result is the same thing here as anywhere else.
     *
     * @return array<string, mixed>
     */
    private function row(Task $task, bool $canUpdate): array
    {
        $assignee = $task->assignee;

        return [
            'id' => $task->id,
            /*
             * The same key My Tasks sends, because both screens draw the same row component and
             * a shape that differs by screen is the shape one of the two gets wrong. Results are
             * read here today; the flag is the truth about the row either way.
             */
            'canUpdate' => $canUpdate,
            'title' => $task->title,
            'dueAt' => $task->due_at?->toIso8601String(),
            'completedAt' => $task->completed_at?->toIso8601String(),
            'priority' => $task->priority->value,
            'comments' => (int) ($task->comments_count ?? 0),
            'assignee' => $assignee === null ? null : [
                'id' => $assignee->id,
                'name' => $assignee->name,
                'email' => $assignee->email,
                'avatar' => null,
            ],
            'tags' => array_values($task->tags
                ->map(fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color?->value,
                ])
                ->all()),
            // Only the projects this reader can open — the same rule `MyTasksQuery` follows.
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
