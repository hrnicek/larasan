<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Jobs;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Actions\RemoveTaskCollaborator;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Access is revoked synchronously before this runs; the job only clears stale assignments.
 */
final class ReleaseRemovedMembersWork implements ShouldQueue
{
    use Queueable;

    private const CHUNK = 100;

    public function __construct(
        private readonly string $workspaceId,
        private readonly int $removedUserId,
        private readonly int $actorId,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(AssignTask $assign, RemoveTaskCollaborator $removeCollaborator): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);
        $actor = User::query()->find($this->actorId);

        if (! $workspace instanceof Workspace || ! $actor instanceof User) {
            return;
        }

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

        Task::query()
            ->where('workspace_id', $workspace->id)
            ->whereHas('collaborations', fn (Builder $collaborations): Builder => $collaborations
                ->where('user_id', $this->removedUserId))
            ->with('workspace')
            ->chunkById(self::CHUNK, function (Collection $tasks) use ($removeCollaborator, $actor): void {
                foreach ($tasks as $task) {
                    $removeCollaborator->handle($task, $actor, $this->removedUserId);
                }
            });
    }
}
