<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Jobs;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Events\TaskCollaboratorRemoved;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
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

    public function handle(Dispatcher $events): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);

        if (! $workspace instanceof Workspace || $this->wasReadmitted($workspace)) {
            return;
        }

        // The removal was authorized when it happened, so the remover's current access is not re-checked;
        // the account must still exist because activities.actor_id references it.
        if (User::query()->whereKey($this->actorId)->doesntExist()) {
            return;
        }

        Task::query()
            ->where('workspace_id', $workspace->id)
            ->where('assignee_id', $this->removedUserId)
            ->chunkById(self::CHUNK, function (Collection $tasks) use ($events): void {
                foreach ($tasks as $task) {
                    $task->forceFill(['assignee_id' => null])->save();

                    $events->dispatch(new TaskAssigned($task->id, $task->workspace_id, null, $this->actorId));
                }
            });

        Task::query()
            ->where('workspace_id', $workspace->id)
            ->whereHas('collaborations', fn (Builder $collaborations): Builder => $collaborations
                ->where('user_id', $this->removedUserId))
            ->chunkById(self::CHUNK, function (Collection $tasks) use ($events): void {
                foreach ($tasks as $task) {
                    $task->collaborations()->where('user_id', $this->removedUserId)->first()?->delete();

                    $events->dispatch(new TaskCollaboratorRemoved($task->id, $task->workspace_id, $this->removedUserId, $this->actorId));
                }
            });
    }

    private function wasReadmitted(Workspace $workspace): bool
    {
        return $workspace->memberships()
            ->where('user_id', $this->removedUserId)
            ->where('status', WorkspaceMembershipStatus::Active->value)
            ->exists();
    }
}
