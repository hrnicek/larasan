<?php

declare(strict_types=1);

namespace App\Domain\Task\Queries;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Task\Ancestry\ParentChain;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final readonly class ReachableTasks
{
    /**
     * The unplaced ancestors of the outer `tasks` row, up to and including the first placed one. The
     * walk is bounded by the depth limit, so a parent loop in the data ends without reaching a root.
     */
    private const string ANCESTOR_WALK = <<<'SQL'
        (
            with recursive walk (id, parent_id, workspace_id, depth) as (
                select parent.id, parent.parent_id, parent.workspace_id, 1
                from tasks as parent
                where parent.id = tasks.parent_id and parent.workspace_id = tasks.workspace_id
                union all
                select parent.id, parent.parent_id, parent.workspace_id, walk.depth + 1
                from walk
                join tasks as parent on parent.id = walk.parent_id and parent.workspace_id = walk.workspace_id
                where walk.depth < ?
                    and not exists (select 1 from task_project_memberships as placement where placement.task_id = walk.id)
            )
            select id, parent_id from walk
        ) as ancestors
        SQL;

    public function __construct(private VisibleProjectsForUser $visibleProjects) {}

    /**
     * @param  Builder<Task>  $tasks
     * @return Builder<Task>
     */
    public function constrain(Builder $tasks, Workspace $workspace, User $actor): Builder
    {
        $membership = $workspace->membershipFor($actor);

        return $this->governedBy(
            $tasks->where('tasks.workspace_id', $workspace->id),
            $this->projectIds($workspace, $actor),
            includeWorkspaceWork: $membership?->status->grantsAccess() === true && ! $membership->role->isGuest(),
        );
    }

    /**
     * A task is governed by its own placements; an unplaced task by those of its nearest placed
     * ancestor. Only an unplaced task with no parent is workspace work. See ADR-0023.
     *
     * @param  Builder<Task>  $tasks
     * @param  Builder<Project>  $projectIds
     * @return Builder<Task>
     */
    public function governedBy(Builder $tasks, Builder $projectIds, bool $includeWorkspaceWork): Builder
    {
        return $tasks->where(function (Builder $governed) use ($projectIds, $includeWorkspaceWork): void {
            $governed
                ->whereHas('placements', fn (Builder $placements): Builder => $placements->whereIn('project_id', $projectIds))
                ->orWhere(fn (Builder $unplaced): Builder => $unplaced
                    ->whereDoesntHave('placements')
                    ->where(function (Builder $inherited) use ($projectIds, $includeWorkspaceWork): void {
                        if ($includeWorkspaceWork) {
                            $inherited->whereNull('tasks.parent_id');
                        }

                        $inherited->orWhereExists(fn (QueryBuilder $ancestors): QueryBuilder => $ancestors
                            ->fromRaw(self::ANCESTOR_WALK, [ParentChain::MAX_DEPTH])
                            ->where(function (QueryBuilder $governing) use ($projectIds, $includeWorkspaceWork): void {
                                $governing->whereExists(fn (QueryBuilder $placements): QueryBuilder => $placements
                                    ->from('task_project_memberships as placement')
                                    ->whereColumn('placement.task_id', 'ancestors.id')
                                    ->whereIn('placement.project_id', $projectIds));

                                if ($includeWorkspaceWork) {
                                    $governing->orWhere(fn (QueryBuilder $root): QueryBuilder => $root
                                        ->whereNull('ancestors.parent_id')
                                        ->whereNotExists(fn (QueryBuilder $placements): QueryBuilder => $placements
                                            ->from('task_project_memberships as placement')
                                            ->whereColumn('placement.task_id', 'ancestors.id')));
                                }
                            }));
                    }));
        });
    }

    /**
     * @return Builder<Task>
     */
    public function idsFor(Workspace $workspace, User $actor): Builder
    {
        return $this->constrain(Task::query(), $workspace, $actor)->select('tasks.id');
    }

    /**
     * @return Builder<Project>
     */
    public function projectIds(Workspace $workspace, User $actor): Builder
    {
        return $this->visibleProjects
            ->query($workspace, $actor, includeArchived: true)
            ->select('projects.id');
    }
}
