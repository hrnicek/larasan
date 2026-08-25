<?php

declare(strict_types=1);

namespace App\Domain\Search\Queries;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\ReachableTasks;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The tasks a term finds, for the palette.
 *
 * The engine matches and ranks; PostgreSQL decides what comes back (ADR-0016). Scout's
 * `query()` callback runs on the builder that hydrates the matched keys, which is where
 * `ReachableTasks` goes — so a task in a project this actor cannot open is not returned even
 * when the index still holds it. The index can shorten this list. It cannot widen it.
 */
final readonly class TaskResults
{
    /**
     * Candidates asked of the engine per result wanted.
     *
     * Reach is applied after matching, so a run of candidates can be entirely made of projects
     * this actor cannot open. Asking for more than is wanted makes that a shorter list rather
     * than an empty one; it is not a guarantee, and nothing is served by making it one — the
     * palette shows a handful, and the search screen pages against PostgreSQL.
     */
    private const CANDIDATES_PER_RESULT = 4;

    public function __construct(private ReachableTasks $reachable) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(Workspace $workspace, User $actor, string $term, int $limit = 5): array
    {
        $term = trim($term);

        if ($term === '' || $limit < 1) {
            return [];
        }

        $visible = $this->reachable->projectIds($workspace, $actor);

        $results = Task::search($term)
            ->where('workspace_id', $workspace->id)
            ->query(fn (Builder $tasks): Builder => $this->reachable
                ->constrain($tasks, $workspace, $actor)
                ->with([
                    'assignee:id,name,email',
                    'placements' => fn (Relation $placements) => $placements
                        ->whereIn('project_id', $visible)
                        ->with('project:id,name,color,icon'),
                ]))
            ->take($limit * self::CANDIDATES_PER_RESULT)
            ->get()
            ->take($limit)
            ->map(fn (Task $task): array => $this->row($task));

        return array_values($results->all());
    }

    /**
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
            'assignee' => $assignee === null ? null : [
                'id' => $assignee->id,
                'name' => $assignee->name,
                'email' => $assignee->email,
            ],
            // Only the projects this reader may open: a chip is a project's name, and a task
            // reached through one project must not name another the reader has never seen.
            'projects' => array_values($task->placements
                ->map(fn (TaskProjectMembership $placement): array => [
                    'id' => $placement->project->id,
                    'name' => $placement->project->name,
                    'color' => $placement->project->color?->value,
                    'icon' => $placement->project->icon?->value,
                ])
                ->all()),
        ];
    }
}
