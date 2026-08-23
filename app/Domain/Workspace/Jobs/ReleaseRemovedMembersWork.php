<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Jobs;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Work assigned to somebody who has been removed goes back to the project.
 *
 * Queued rather than done in the request (TASK-180-019). Each task is unassigned through
 * `AssignTask` so it produces the same `TaskAssigned` event any other unassignment does and the
 * history reads as one thing — which is four queries per task, and somebody who is leaving may
 * be holding hundreds. The removal itself is not here: revoking the membership and the project
 * grants is the security answer, and a security answer must not wait on a queue.
 *
 * The consequence is worth stating plainly: for the moments between the removal and this job,
 * tasks still carry the name of somebody who can no longer open them. That is a stale label, not
 * an access grant — their membership is already revoked, so every read and write refuses them.
 *
 * Ids rather than models, as everything queued here does: a job that deserialises a model gets
 * whatever the row looked like when it ran.
 */
final class ReleaseRemovedMembersWork implements ShouldQueue
{
    use Queueable;

    /** How many tasks are unassigned per read. */
    private const CHUNK = 100;

    public function __construct(
        private readonly string $workspaceId,
        private readonly int $removedUserId,
        private readonly int $actorId,
    ) {}

    /**
     * The `default` queue, deliberately. This is neither a board update nor somebody's inbox: it
     * is bulk work that nobody is waiting on, and it must not sit in front of either.
     */
    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(AssignTask $assign): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);
        $actor = User::query()->find($this->actorId);

        if (! $workspace instanceof Workspace || ! $actor instanceof User) {
            return;
        }

        /*
         * If the person was re-invited between the removal and this job, their work is theirs
         * again and taking it away would undo a decision somebody made after this one.
         */
        if ($workspace->membershipFor($actor)?->status->grantsAccess() !== true) {
            return;
        }

        $stillRemoved = $workspace->memberships()
            ->where('user_id', $this->removedUserId)
            ->where('status', WorkspaceMembershipStatus::Active->value)
            ->doesntExist();

        if (! $stillRemoved) {
            return;
        }

        Task::query()
            ->where('workspace_id', $workspace->id)
            ->where('assignee_id', $this->removedUserId)
            ->with('workspace')
            ->chunkById(self::CHUNK, function (Collection $tasks) use ($assign, $actor): void {
                foreach ($tasks as $task) {
                    $assign->handle($task, $actor, null);
                }
            });
    }
}
