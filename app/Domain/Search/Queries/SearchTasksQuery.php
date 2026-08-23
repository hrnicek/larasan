<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

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

    public function __construct(private VisibleProjectsForUser $visibleProjects) {}

    /**
     * @param  array{project?: string, assignee?: int, completed?: bool}  $filters
     * @return array{
     *     tasks: list<array<string, mixed>>,
     *     meta: array{term: string, page: int, perPage: int, total: int, hasMore: bool},
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

        $results = $this->paginate($workspace, $actor, $query, $filters, $page, $perPage);

        return [
            'tasks' => array_values($results->getCollection()
                ->map(fn (Task $task): array => $this->row($task))
                ->all()),
            'meta' => [
                'term' => $term,
                'page' => $results->currentPage(),
                'perPage' => $results->perPage(),
                'total' => $results->total(),
                'hasMore' => $results->hasMorePages(),
            ],
        ];
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
     * @param  array{project?: string, assignee?: int, completed?: bool}  $filters
     * @return LengthAwarePaginator<int, Task>
     */
    private function paginate(
        Workspace $workspace,
        User $actor,
        string $query,
        array $filters,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        $visible = $this->visibleProjects
            ->query($workspace, $actor, includeArchived: true)
            ->select('projects.id');

        $isGuest = $workspace->membershipFor($actor)?->role->isGuest() === true;

        $tasks = Task::query()
            ->where('tasks.workspace_id', $workspace->id)
            ->whereRaw("search_vector @@ to_tsquery('simple', immutable_unaccent(?))", [$query])
            /*
             * Reach, as one condition: a task in a project the actor can open, or in no project
             * at all when they are not a guest — guests hold projects, and a task in none was
             * never given to them.
             */
            ->where(function (Builder $reachable) use ($visible, $isGuest): void {
                $reachable->whereHas(
                    'placements',
                    fn (Builder $placements): Builder => $placements->whereIn('project_id', $visible),
                );

                if (! $isGuest) {
                    $reachable->orWhereDoesntHave('placements');
                }
            })
            ->select(['id', 'workspace_id', 'title', 'due_at', 'priority', 'completed_at', 'assignee_id'])
            ->withCount('comments')
            ->with([
                'assignee:id,name,email',
                'placements' => fn (Relation $placements) => $placements
                    ->whereIn('project_id', $visible)
                    ->with('project:id,name,color'),
            ])
            // Rank first, then the key: `created_at` is `timestamp(0)` and ties are common, so
            // without a second key a page would reshuffle between requests.
            ->orderByRaw("ts_rank_cd(search_vector, to_tsquery('simple', immutable_unaccent(?))) desc", [$query])
            ->orderByDesc('id');

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
     * @return array{tasks: list<array<string, mixed>>, meta: array{term: string, page: int, perPage: int, total: int, hasMore: bool}}
     */
    private function empty(string $term, int $page, int $perPage): array
    {
        return [
            'tasks' => [],
            'meta' => ['term' => $term, 'page' => $page, 'perPage' => $perPage, 'total' => 0, 'hasMore' => false],
        ];
    }

    /**
     * The row the other lists draw, so a result is the same thing here as anywhere else.
     *
     * @return array<string, mixed>
     */
    private function row(Task $task): array
    {
        $assignee = $task->assignee;

        return [
            'id' => $task->id,
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
