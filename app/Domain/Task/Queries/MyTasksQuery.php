<?php

declare(strict_types=1);

namespace App\Domain\Task\Queries;

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Shared\Enums\MyTasksTab;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * What one person is responsible for, in this workspace.
 *
 * The workspace is proved inside the query rather than assumed from the caller (ADR-0005), and
 * the projects listed beside a task are only the ones the reader can reach: a task can appear in
 * a project they were never given, and naming it here would leak a project through a task they
 * are allowed to see (ADR-0006).
 *
 * A task assigned to somebody with **no due date** appears in Upcoming, after everything dated.
 * The dated tabs are the whole of what somebody was given, so a task that matched none of them
 * would be work they had been handed and could not find.
 *
 * Starred is the exception, and the only tab that drops the assignee: it answers "what did I want
 * at hand" rather than "what am I responsible for", so it may hold a task somebody else is doing.
 * Reach is asked there for the same reason it is not asked anywhere else here — assignment proves
 * nothing about a task the reader starred and then lost access to.
 */
final readonly class MyTasksQuery
{
    public const PER_PAGE = 25;

    public function __construct(
        private VisibleProjectsForUser $visibleProjects,
        private ReachableTasks $reachableTasks,
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

        return [
            'tasks' => array_values($tasks->getCollection()
                ->map(fn (Task $task): array => $this->row($task))
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

        $query = Task::query()
            ->where('workspace_id', $workspace->id)
            ->select(['id', 'workspace_id', 'title', 'due_at', 'priority', 'completed_at', 'assignee_id'])
            // The count the row draws, as a subquery rather than a read per row (TASK-110-015).
            ->withCount('comments')
            ->with([
                'assignee:id,name,email',
                // Only the placements whose project the reader can open, and the project itself
                // — one query for the page rather than one per row.
                'placements' => fn (Relation $placements) => $placements
                    ->whereIn('project_id', $visible)
                    ->with('project:id,name,color'),
            ]);

        if ($tab->isAboutAssignment()) {
            $query->where('assignee_id', $actor->id);
        } else {
            /*
             * Starred lists what this person marked, whoever it belongs to — so the reach rule
             * every other list uses has to be asked here rather than inherited from assignment.
             */
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
            /*
             * Everything still to come, and everything with no date at all. `orderByRaw` puts
             * the undated last: they are work somebody has been given, not work due first.
             */
            MyTasksTab::Upcoming => $query->whereNull('completed_at')
                ->where(fn (Builder $inner) => $inner->whereNull('due_at')->orWhere('due_at', '>=', $tomorrow)),
            MyTasksTab::Completed => $query->whereNotNull('completed_at'),
            // Starred says nothing about dates or completion: it is the list somebody built, and
            // hiding half of it would make the star look like it had stopped working.
            MyTasksTab::Starred => $query,
        };

        if ($tab->showsCompleted()) {
            // A finished list reads newest first: what was done most recently is what somebody
            // is checking.
            $query->orderByDesc('completed_at')->orderBy('id');

            return;
        }

        if ($tab === MyTasksTab::Starred) {
            // Finished work sinks rather than disappearing, so the tab opens on what is still to
            // do without pretending the rest was never starred.
            $query->orderByRaw('completed_at is not null');
        }

        $query->orderByRaw('due_at is null')
            ->orderBy('due_at')
            // Then by priority, highest first. The enum's values sort the wrong way as strings,
            // so the order is written out rather than left to the column.
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderBy('id');
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
            'comments' => (int) ($task->comments_count ?? 0),
            /*
             * Always the reader, and sent anyway: the row is the list view's component, and a
             * shape that differs by screen is the shape one of the two screens gets wrong.
             */
            'assignee' => $assignee === null ? null : [
                'id' => $assignee->id,
                'name' => $assignee->name,
                'email' => $assignee->email,
                'avatar' => null,
            ],
            // Where the task lives, which is where multi-project membership becomes visible
            // (`docs/ui/inbox.md`) — and only the parts of it this reader may know about.
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
