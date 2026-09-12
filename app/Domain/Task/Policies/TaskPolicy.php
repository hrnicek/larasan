<?php

declare(strict_types=1);

namespace App\Domain\Task\Policies;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\ChangeableProjectsForUser;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TaskPolicy
{
    /**
     * @var array<string, list<string>>
     */
    private array $boards = [];

    /** @var array<string, bool> */
    private array $visible = [];

    /** @var array<string, bool> */
    private array $answers = [];

    public function view(User $user, Task $task): bool
    {
        $membership = $task->workspace->membershipFor($user);

        if ($membership?->status->grantsAccess() !== true) {
            return false;
        }

        if ($this->appearsInAProjectVisibleTo($user, $task)) {
            return true;
        }

        // A task in no project is readable by workspace members, but never by guests.
        return ! $membership->role->isGuest() && $this->boardsOf($task) === [];
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
            && $this->onABoardThatAllows(
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
            && $this->onABoardThatAllows(
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
    private function onABoardThatAllows(Task $task, callable $projects, string $memo): bool
    {
        $boards = $this->boardsOf($task);

        // A task on no board is settled by view() and the workspace capability alone.
        if ($boards === []) {
            return true;
        }

        $key = $task->id.'|'.$memo;

        return $this->answers[$key] ??= $projects($task->workspace)
            ->whereIn('projects.id', $boards)
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function boardsOf(Task $task): array
    {
        // Queried rather than read from a loaded relation, which callers may have narrowed by visibility.
        return $this->boards[$task->id] ??= array_values(array_map(
            fn (mixed $projectId): string => (string) $projectId,
            $task->placements()->pluck('project_id')->all(),
        ));
    }

    private function changeableProjects(): ChangeableProjectsForUser
    {
        return app(ChangeableProjectsForUser::class);
    }

    /** Emptied by `AppServiceProvider` whenever a membership or a placement is written. */
    public function flush(): void
    {
        $this->boards = [];
        $this->visible = [];
        $this->answers = [];
    }

    private function appearsInAProjectVisibleTo(User $user, Task $task): bool
    {
        $boards = $this->boardsOf($task);

        if ($boards === []) {
            return false;
        }

        return $this->visible[$task->id.'|'.$user->id] ??= app(VisibleProjectsForUser::class)
            ->query($task->workspace, $user, includeArchived: true)
            ->whereIn('projects.id', $boards)
            ->exists();
    }
}
