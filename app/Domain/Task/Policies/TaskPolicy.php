<?php

declare(strict_types=1);

namespace App\Domain\Task\Policies;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\ChangeableProjectsForUser;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\ReachableTasks;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TaskPolicy
{
    /** @var array<string, bool> */
    private array $answers = [];

    public function view(User $user, Task $task): bool
    {
        if ($task->workspace->membershipFor($user)?->status->grantsAccess() !== true) {
            return false;
        }

        return $this->answers[$task->id.'|view:'.$user->id] ??= $this->reachableTasks()
            ->constrain($this->itself($task), $task->workspace, $user)
            ->exists();
    }

    public function update(User $user, Task $task): bool
    {
        return $this->allows($user, $task, Capability::TaskUpdate);
    }

    public function complete(User $user, Task $task): bool
    {
        return $this->allows($user, $task, Capability::TaskUpdate);
    }

    public function assign(User $user, Task $task): bool
    {
        return $this->allows($user, $task, Capability::TaskAssign);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->allows($user, $task, Capability::TaskDelete);
    }

    public function comment(User $user, Task $task): bool
    {
        return $this->view($user, $task)
            && $task->workspace->membershipFor($user)?->allows(Capability::CommentCreate) === true
            && $this->governedByAProjectThatAllows(
                $task,
                fn (Workspace $workspace): Builder => $this->changeableProjects()->commentable($workspace, $user),
                'comment:'.$user->id,
            );
    }

    public function attach(User $user, Task $task): bool
    {
        return $this->allows($user, $task, Capability::FileUpload);
    }

    private function allows(User $user, Task $task, Capability $capability): bool
    {
        return $this->view($user, $task)
            && $task->workspace->membershipFor($user)?->allows($capability) === true
            && $this->governedByAProjectThatAllows(
                $task,
                fn (Workspace $workspace): Builder => $this->changeableProjects()
                    ->query($workspace, $user, $capability),
                // Every editing capability checks the same access levels, so they share one memo entry.
                'edit:'.$user->id,
            );
    }

    /**
     * @param  callable(Workspace): Builder<Project>  $projects
     * @param  string  $memo  memo key for the question being asked
     */
    private function governedByAProjectThatAllows(Task $task, callable $projects, string $memo): bool
    {
        // view() has already kept guests off workspace work.
        return $this->answers[$task->id.'|'.$memo] ??= $this->reachableTasks()
            ->governedBy(
                $this->itself($task),
                $projects($task->workspace)->select('projects.id'),
                includeWorkspaceWork: true,
            )
            ->exists();
    }

    /**
     * @return Builder<Task>
     */
    private function itself(Task $task): Builder
    {
        return Task::query()->withTrashed()->whereKey($task->id);
    }

    private function reachableTasks(): ReachableTasks
    {
        return app(ReachableTasks::class);
    }

    private function changeableProjects(): ChangeableProjectsForUser
    {
        return app(ChangeableProjectsForUser::class);
    }

    /** Emptied by `AppServiceProvider` whenever a membership or a placement is written. */
    public function flush(): void
    {
        $this->answers = [];
    }
}
