<?php

declare(strict_types=1);

namespace App\Domain\Task\Queries;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Queries\ChangeableProjectsForUser;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\MyTasksTab;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class MyTasksQuery
{
    public const PER_PAGE = 25;

    public function __construct(
        private VisibleProjectsForUser $visibleProjects,
        private ReachableTasks $reachableTasks,
        private ChangeableProjectsForUser $changeableProjects,
    ) {}

    /**
     * @return array{
     *     tasks: list<array<string, mixed>>,
     *     meta: array{tab: string, page: int, perPage: int, total: int, hasMore: bool},
     * }
     */
    public function __invoke(
        Workspace $workspace,
        User $actor,
        MyTasksTab $tab,
        int $page = 1,
        int $perPage = self::PER_PAGE,
    ): array {
        $tasks = $this->paginate($workspace, $actor, $tab, $page, $perPage);

        $workspaceMayUpdate = $workspace->membershipFor($actor)?->allows(Capability::TaskUpdate) === true;

        return [
            'tasks' => array_values($tasks->getCollection()
                ->map(fn (Task $task): array => $this->row($task, $workspaceMayUpdate))
                ->all()),
            'meta' => [
                'tab' => $tab->value,
                'page' => $tasks->currentPage(),
                'perPage' => $tasks->perPage(),
                'total' => $tasks->total(),
                'hasMore' => $tasks->hasMorePages(),
            ],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Task>
     */
    private function paginate(
        Workspace $workspace,
        User $actor,
        MyTasksTab $tab,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        $visible = $this->visibleProjects
            ->query($workspace, $actor, includeArchived: true)
            ->select('projects.id');

        $changeable = $this->changeableProjects
            ->query($workspace, $actor, Capability::TaskUpdate)
            ->select('projects.id');

        $query = Task::query()
            ->where('workspace_id', $workspace->id)
            ->select(['id', 'workspace_id', 'title', 'due_at', 'priority', 'completed_at', 'assignee_id'])
            ->withCount('comments')
            ->withCount('placements')
            ->withExists(['placements as on_a_changeable_board' => fn (Builder $placements): Builder => $placements
                ->whereIn('project_id', $changeable)])
            ->with([
                PersonSummary::eager('assignee'),
                'tags:id,name,color',
                // Constrained so a project the reader cannot open is never named. See ADR-0006.
                'placements' => fn (Relation $placements) => $placements
                    ->whereIn('project_id', $visible)
                    ->with('project:id,name,color'),
            ]);

        if ($tab->isAboutAssignment()) {
            $query->where('assignee_id', $actor->id);
        } else {
            // Starred tasks are not necessarily assigned to the actor, so reach must be checked explicitly.
            $this->reachableTasks->constrain($query, $workspace, $actor);
            $query->whereHas('stars', fn (Builder $stars): Builder => $stars->where('user_id', $actor->id));
        }

        $this->applyTab($query, $tab);

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function applyTab(Builder $query, MyTasksTab $tab): void
    {
        $today = now()->startOfDay();
        $tomorrow = now()->addDay()->startOfDay();

        match ($tab) {
            MyTasksTab::Today => $query->whereNull('completed_at')
                ->whereBetween('due_at', [$today, $tomorrow->copy()->subSecond()]),
            MyTasksTab::Overdue => $query->whereNull('completed_at')
                ->whereNotNull('due_at')
                ->where('due_at', '<', $today),
            MyTasksTab::Upcoming => $query->whereNull('completed_at')
                ->where(fn (Builder $inner) => $inner->whereNull('due_at')->orWhere('due_at', '>=', $tomorrow)),
            MyTasksTab::Completed => $query->whereNotNull('completed_at'),
            MyTasksTab::Starred => $query,
        };

        if ($tab->showsCompleted()) {
            $query->orderByDesc('completed_at')->orderBy('id');

            return;
        }

        if ($tab === MyTasksTab::Starred) {
            $query->orderByRaw('completed_at is not null');
        }

        $query->orderByRaw('due_at is null')
            ->orderBy('due_at')
            // Priority values do not sort by urgency as strings.
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderBy('id');
    }

    private function canUpdate(Task $task, bool $workspaceMayUpdate): bool
    {
        if (! $workspaceMayUpdate) {
            return false;
        }

        return (int) ($task->placements_count ?? 0) === 0
            || (bool) ($task->on_a_changeable_board ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Task $task, bool $workspaceMayUpdate): array
    {
        $assignee = $task->assignee;

        return [
            'id' => $task->id,
            'canUpdate' => $this->canUpdate($task, $workspaceMayUpdate),
            'title' => $task->title,
            'dueAt' => $task->due_at?->toIso8601String(),
            'completedAt' => $task->completed_at?->toIso8601String(),
            'priority' => $task->priority->value,
            'comments' => (int) ($task->comments_count ?? 0),
            'assignee' => PersonSummary::fromNullable($assignee),
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
