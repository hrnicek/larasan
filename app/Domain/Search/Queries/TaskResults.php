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
 * Scout's `query()` callback constrains the hydration query, so reach holds even when the index is stale. See ADR-0016.
 */
final readonly class TaskResults
{
    /**
     * Over-fetch factor, because reach filtering after matching can discard candidates.
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
