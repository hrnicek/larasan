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

/**
 * Two questions, asked in this order: can the actor reach this task at all, and does their
 * workspace role allow the thing they are trying to do.
 *
 * Reach is where placement arrives (TASK-070-017). A task appears in projects, and a project
 * can be private, so "a member of the workspace" is no longer the whole answer: a task that
 * appears **only** in projects the actor cannot open is not theirs to read, and a guest —
 * who reaches only what they were explicitly given — sees a task exactly when it appears in
 * a project they were given (ADR-0003 with ADR-0006 and ADR-0010).
 *
 * Resolved by auto-discovery, and `TaskPolicyTest` asserts that resolution — the
 * `app/Domain/<Context>/Policies` pairing is not the layout the framework documents, so a
 * namespace move would otherwise stop authorizing in silence.
 */
class TaskPolicy
{
    /**
     * The boards each task sits on, read once per task for the length of one request.
     *
     * A panel asks this policy four times about one task — read it, change it, comment on it,
     * attach to it — and each of those used to re-read the same two things. The memo is
     * request-scoped and nothing longer: an authorization answer that outlived the row it came
     * from is the failure mode every "clever" permission cache has, which is why
     * `AppServiceProvider` empties this whenever a membership or a placement is written.
     *
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

        /*
         * A task in no project at all is workspace work — the inbox, a quick capture, a
         * subtask nobody has filed yet — and a member of the workspace may read it. A guest
         * may not: they hold projects, and a task with no project was never given to them.
         */
        return ! $membership->role->isGuest() && $this->boardsOf($task) === [];
    }

    public function update(User $user, Task $task): bool
    {
        return $this->allows($user, $task, Capability::TaskUpdate);
    }

    public function complete(User $user, Task $task): bool
    {
        // Completion is an edit of the task's own state, so it asks the same capability the
        // Action does rather than inventing a `task.complete` nobody grants.
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

    /**
     * Saying something about a task, which is taking part in the project it sits in — a
     * Commenter is exactly the level that may do this and nothing else (ADR-0006).
     */
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

    /**
     * Adding a document to a task is changing the task, so it asks what renaming it asks —
     * with `file.upload` as the workspace half, because uploading is its own capability.
     */
    public function attach(User $user, Task $task): bool
    {
        return $this->allows($user, $task, Capability::FileUpload);
    }

    /**
     * Reach, then capability, then the boards the task is on.
     *
     * The third question was missing until TASK-260-001, and its absence was the whole of
     * `ProjectAccessLevel` for tasks: a Viewer or a Commenter on a project could rename,
     * complete, reassign and delete every card in it, and an archived board's cards stayed
     * editable although nothing could be moved on it. ADR-0006 says an Editor modifies tasks
     * and a Commenter does not, and this is where that is asked.
     */
    private function allows(User $user, Task $task, Capability $capability): bool
    {
        return $this->view($user, $task)
            && $task->workspace->membershipFor($user)?->allows($capability) === true
            && $this->onABoardThatAllows(
                $task,
                fn (Workspace $workspace): Builder => $this->changeableProjects()
                    ->query($workspace, $user, $capability),
                /*
                 * Every editing capability asks the same set of access levels, so they share
                 * one answer: the capability itself has already been checked above, against the
                 * workspace membership, and cannot change what the board says.
                 */
                'edit:'.$user->id,
            );
    }

    /**
     * Whether at least one of the task's boards answers yes.
     *
     * **A task on no board at all is workspace work** — the inbox, a quick capture, a subtask
     * nobody has filed — and reach has already been settled for it by `view()`. Otherwise it
     * takes one board: a task appears in several (ADR-0003), and being shut out of one of them
     * is not being shut out of the task, the same way reaching one is enough to read it.
     *
     * One query against the boards this task is on, rather than one per placement — the reason
     * `appearsInAProjectVisibleTo()` is written the way it is (ADR-0005).
     *
     * @param  callable(Workspace): Builder<Project>  $projects
     * @param  string  $memo  what the caller is asking, so two questions do not share an answer
     */
    private function onABoardThatAllows(Task $task, callable $projects, string $memo): bool
    {
        $boards = $this->boardsOf($task);

        if ($boards === []) {
            return true;
        }

        $key = $task->id.'|'.$memo;

        return $this->answers[$key] ??= $projects($task->workspace)
            ->whereIn('projects.id', $boards)
            ->exists();
    }

    /**
     * The projects this task appears in, by key.
     *
     * Read rather than taken from a loaded `placements` relation: several screens load that
     * relation already narrowed to what the reader may see, and an authorization answer taken
     * from a filtered list is an answer to a different question.
     *
     * @return list<string>
     */
    private function boardsOf(Task $task): array
    {
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

    /**
     * Asked as one query rather than per placement: a task can be in many projects, and
     * `Project::isVisibleTo()` per row is the N+1 that `VisibleProjectsForUser` exists to
     * prevent (ADR-0005). Archived projects count — an archived board is read-only, not
     * hidden.
     */
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
