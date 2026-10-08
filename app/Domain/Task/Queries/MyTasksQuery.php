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
use Illuminate\Support\Facades\DB;

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
        $people = PersonSummary::for($workspace, $actor);

        return [
            'tasks' => array_values($tasks->getCollection()
                ->map(fn (Task $task): array => $this->row($task, $workspaceMayUpdate, $people))
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

        $governedByAChangeableProject = DB::query()
            ->fromSub($this->reachableTasks->governedBy(
                Task::query()->where('tasks.workspace_id', $workspace->id)->select('tasks.id'),
                $this->changeableProjects->query($workspace, $actor, Capability::TaskUpdate)->select('projects.id'),
                includeWorkspaceWork: true,
            ), 'editable')
            ->whereColumn('editable.id', 'tasks.id')
            ->selectRaw('count(*) > 0');

        $query = Task::query()
            ->where('workspace_id', $workspace->id)
            ->select(['id', 'workspace_id', 'title', 'due_at', 'priority', 'completed_at', 'assignee_id'])
            ->selectSub($governedByAChangeableProject, 'governed_by_a_changeable_project')
            ->withCount('comments')
            ->with([
                PersonSummary::eager('assignee'),
                'tags:id,name,color',
                // Constrained so a project the reader cannot open is never named. See ADR-0006.
                'placements' => fn (Relation $placements) => $placements
                    ->whereIn('project_id', $visible)
                    ->with('project:id,name,color'),
            ]);

        // An assignee or a starrer may since have lost access to the task.
        $this->reachableTasks->constrain($query, $workspace, $actor);

        if ($tab->isAboutAssignment()) {
            $query->where('assignee_id', $actor->id);
        } else {
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
        return $workspaceMayUpdate && $task->getAttribute('governed_by_a_changeable_project') === true;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Task $task, bool $workspaceMayUpdate, PersonSummary $people): array
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
