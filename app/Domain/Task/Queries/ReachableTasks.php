<?php

declare(strict_types=1);

namespace App\Domain\Task\Queries;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tasks one person may see in one workspace, as a constraint rather than a list.
 *
 * A task is reachable when it sits in a project the actor may open, or in no project at all and
 * the actor is not a guest — guests hold projects, and a task in none was never given to them
 * (ADR-0006, TASK-070-017). That sentence used to be written inside `SearchTasksQuery`; it is
 * here because the search engine now has a second caller for it, and a rule with two copies is a
 * rule with one that will be forgotten.
 *
 * It constrains a builder rather than returning rows, because both callers need it joined to
 * their own query: a leak is invisible in a list nobody expected to be complete.
 */
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
     * The keys of the projects this actor may open, as a subquery.
     *
     * Callers need it a second time to constrain what they *show*: a task's project chips are
     * drawn from its placements, and an unconstrained eager load would name a private project on
     * a task the actor reached through a different one.
     *
     * Archived projects are included: a task in one is still reachable, and a search that hid it
     * would be hiding work rather than tidying it.
     *
     * @return Builder<Project>
     */
    public function projectIds(Workspace $workspace, User $actor): Builder
    {
        return $this->visibleProjects
            ->query($workspace, $actor, includeArchived: true)
            ->select('projects.id');
    }
}
