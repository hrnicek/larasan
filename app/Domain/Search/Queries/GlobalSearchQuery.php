<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Shared\Enums\SearchKind;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One term, five kinds of answer.
 *
 * Each kind is asked separately and ranked within itself, because a task and a person have
 * nothing to be ranked against each other by: the palette shows the best few of each and lets a
 * reader's eye do what a score cannot.
 *
 * When the engine cannot be reached, tasks are still answered from PostgreSQL's own full-text
 * index (ADR-0012, kept as the degraded path by ADR-0016) and the response says so. Search dying
 * entirely because a container restarted is a worse failure than a narrower answer.
 */
final readonly class GlobalSearchQuery
{
    /** How many of each kind the palette shows before it stops being a palette. */
    public const PER_KIND = 5;

    /** How many one kind gets when it is the only kind asked for — the tabs. */
    public const PER_KIND_ALONE = 20;

    public function __construct(
        private TaskResults $tasks,
        private ProjectResults $projects,
        private PersonResults $people,
        private MessageResults $messages,
        private PageResults $pages,
        private SearchTasksQuery $tasksInDatabase,
    ) {}

    /**
     * @return array{
     *     results: array<string, list<array<string, mixed>>>,
     *     meta: array{term: string, kind: string|null, degraded: bool},
     * }
     */
    public function __invoke(Workspace $workspace, User $actor, string $term, ?SearchKind $kind = null): array
    {
        $term = trim($term);
        $limit = $kind === null ? self::PER_KIND : self::PER_KIND_ALONE;

        if ($term === '') {
            return $this->answer($this->nothing($kind), $term, $kind, degraded: false);
        }

        $results = $this->nothing($kind);
        $degraded = false;

        foreach (array_keys($results) as $name) {
            $wanted = SearchKind::from($name);

            try {
                $results[$name] = $this->fromEngine($wanted, $workspace, $actor, $term, $limit);
            } catch (Throwable $failure) {
                /*
                 * The engine is a second service, and services stop — one index at a time, in the
                 * case of a deployment that has not run `scout:import` yet. One kind failing must
                 * not take the others with it, which is why this is caught per kind rather than
                 * around all of them.
                 */
                Log::warning('Search fell back for one kind.', [
                    'kind' => $name,
                    'exception' => $failure->getMessage(),
                ]);

                $degraded = true;
                $results[$name] = $wanted === SearchKind::Tasks
                    ? $this->tasksFromDatabase($workspace, $actor, $term, $limit)
                    : [];
            }
        }

        return $this->answer($results, $term, $kind, degraded: $degraded);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fromEngine(SearchKind $kind, Workspace $workspace, User $actor, string $term, int $limit): array
    {
        $results = match ($kind) {
            SearchKind::Tasks => ($this->tasks)($workspace, $actor, $term, $limit),
            SearchKind::Projects => ($this->projects)($workspace, $actor, $term, $limit),
            SearchKind::People => ($this->people)($workspace, $actor, $term, $limit),
            SearchKind::Messages => ($this->messages)($workspace, $actor, $term, $limit),
            SearchKind::Pages => ($this->pages)($workspace, $actor, $term, $limit),
        };

        return $results;
    }

    /**
     * The degraded path: PostgreSQL's own full-text index, tasks only, no typo tolerance
     * (ADR-0012, kept by ADR-0016). Search answering less is better than search answering
     * nothing because a container restarted.
     *
     * @return list<array<string, mixed>>
     */
    private function tasksFromDatabase(Workspace $workspace, User $actor, string $term, int $limit): array
    {
        return ($this->tasksInDatabase)($workspace, $actor, $term, page: 1, filters: [], perPage: $limit)['tasks'];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function nothing(?SearchKind $kind): array
    {
        $kinds = $kind === null ? SearchKind::cases() : [$kind];

        return array_reduce(
            $kinds,
            function (array $empty, SearchKind $candidate): array {
                $empty[$candidate->value] = [];

                return $empty;
            },
            [],
        );
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $results
     * @return array{
     *     results: array<string, list<array<string, mixed>>>,
     *     meta: array{term: string, kind: string|null, degraded: bool},
     * }
     */
    private function answer(array $results, string $term, ?SearchKind $kind, bool $degraded): array
    {
        return [
            'results' => $results,
            'meta' => ['term' => $term, 'kind' => $kind?->value, 'degraded' => $degraded],
        ];
    }
}
