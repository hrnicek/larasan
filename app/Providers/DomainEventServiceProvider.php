<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Activity\Listeners\RecordTaskAssigned;
use App\Domain\Activity\Listeners\RecordTaskAttachedToProject;
use App\Domain\Activity\Listeners\RecordTaskCompleted;
use App\Domain\Activity\Listeners\RecordTaskCreated;
use App\Domain\Activity\Listeners\RecordTaskDetachedFromProject;
use App\Domain\Activity\Listeners\RecordTaskReopened;
use App\Domain\Activity\Listeners\RecordTaskUpdated;
use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Notification\Listeners\NotifyAssignee;
use App\Domain\Notification\Listeners\NotifyWatchersOfComment;
use App\Domain\Placement\Events\TaskAttachedToProject;
use App\Domain\Placement\Events\TaskDetachedFromProject;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Events\TaskCompleted;
use App\Domain\Task\Events\TaskCreated;
use App\Domain\Task\Events\TaskReopened;
use App\Domain\Task\Events\TaskUpdated;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Every listener the domain has, written out.
 *
 * Explicit rather than discovered: a listener nobody can see registering is one nobody can
 * prove runs, and the failure mode of discovery is silence — a renamed method or a moved class
 * stops recording history and nothing says so.
 *
 * Not queued. Recording what happened is one insert in the same transaction as the thing that
 * happened; deferring it would mean a task whose history arrives later, or not at all when a
 * worker is down. Notifications, which are slow and external, are queued instead
 * (TASK-110-016).
 *
 * Comments are absent on purpose: the feed reads the `comments` table directly (TASK-110-009),
 * so recording an activity for each one would show every comment twice. So is `TaskDeleted` —
 * a deleted task's history has nobody left to read it.
 */
class DomainEventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        TaskCreated::class => [RecordTaskCreated::class],
        TaskUpdated::class => [RecordTaskUpdated::class],
        TaskCompleted::class => [RecordTaskCompleted::class],
        TaskReopened::class => [RecordTaskReopened::class],
        TaskAssigned::class => [RecordTaskAssigned::class, NotifyAssignee::class],
        TaskAttachedToProject::class => [RecordTaskAttachedToProject::class],
        TaskDetachedFromProject::class => [RecordTaskDetachedFromProject::class],

        // No activity for a comment — the feed reads that table directly — but the people
        // watching still have to hear about it.
        CommentCreated::class => [NotifyWatchersOfComment::class],
    ];

    /**
     * Discovery would undo the point of the map above.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
