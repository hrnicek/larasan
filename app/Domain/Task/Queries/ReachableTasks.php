<?php

declare(strict_types=1);

namespace App\Domain\Task\Queries;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final readonly class ReachableTasks
{
    public function __construct(private VisibleProjectsForUser $visibleProjects) {}

    /**
     * @param  Builder<Task>  $tasks
     * @return Builder<Task>
     */
    public function constrain(Builder $tasks, Workspace $workspace, User $actor): Builder
    {
        $visible = $this->projectIds($workspace, $actor);

        $isGuest = $workspace->membershipFor($actor)?->role->isGuest() === true;

        return $tasks
            ->where('tasks.workspace_id', $workspace->id)
            ->where(function (Builder $reachable) use ($visible, $isGuest): void {
                $reachable->whereHas(
                    'placements',
                    fn (Builder $placements): Builder => $placements->whereIn('project_id', $visible),
                );

                if (! $isGuest) {
                    $reachable->orWhereDoesntHave('placements');
                }
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
