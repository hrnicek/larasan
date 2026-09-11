<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Activity\Listeners\RecordTaskAssigned;
use App\Domain\Activity\Listeners\RecordTaskAttachedToProject;
use App\Domain\Activity\Listeners\RecordTaskCollaboratorAdded;
use App\Domain\Activity\Listeners\RecordTaskCollaboratorRemoved;
use App\Domain\Activity\Listeners\RecordTaskCompleted;
use App\Domain\Activity\Listeners\RecordTaskCreated;
use App\Domain\Activity\Listeners\RecordTaskDetachedFromProject;
use App\Domain\Activity\Listeners\RecordTaskReopened;
use App\Domain\Activity\Listeners\RecordTaskUpdated;
use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Comment\Events\CommentEdited;
use App\Domain\Comment\Listeners\BroadcastCommentChange;
use App\Domain\Notification\Listeners\NotifyAssignee;
use App\Domain\Notification\Listeners\NotifyMentionedPeople;
use App\Domain\Notification\Listeners\NotifyNewCollaborator;
use App\Domain\Notification\Listeners\NotifyWatchersOfComment;
use App\Domain\Page\Events\PageCreated;
use App\Domain\Page\Events\PageDeleted;
use App\Domain\Page\Events\PageMoved;
use App\Domain\Page\Events\PageUpdated;
use App\Domain\Page\Listeners\BroadcastPageChange;
use App\Domain\Placement\Events\TaskAttachedToProject;
use App\Domain\Placement\Events\TaskDetachedFromProject;
use App\Domain\Placement\Events\TaskPlacementMoved;
use App\Domain\Placement\Listeners\BroadcastPlacementChange;
use App\Domain\Project\Events\ProjectArchived;
use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Listeners\BroadcastProjectChange;
use App\Domain\Section\Events\SectionCreated;
use App\Domain\Section\Events\SectionDeleted;
use App\Domain\Section\Events\SectionMoved;
use App\Domain\Section\Events\SectionUpdated;
use App\Domain\Section\Listeners\BroadcastSectionChange;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Events\TaskCollaboratorAdded;
use App\Domain\Task\Events\TaskCollaboratorRemoved;
use App\Domain\Task\Events\TaskCompleted;
use App\Domain\Task\Events\TaskCreated;
use App\Domain\Task\Events\TaskDeleted;
use App\Domain\Task\Events\TaskReopened;
use App\Domain\Task\Events\TaskUpdated;
use App\Domain\Task\Listeners\BroadcastTaskChange;
use App\Domain\Task\Listeners\FollowAssignedTask;
use App\Domain\Task\Listeners\FollowCollaboratedTask;
use App\Domain\Task\Listeners\FollowCommentedTask;
use App\Domain\Workspace\Listeners\ClaimInvitationsForNewAccount;
use Illuminate\Auth\Events\Registered;
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
 * The broadcast listeners are not queued either, and for a different reason: the channels a
 * change may go to depend on where the task is placed **now**, and a listener that ran a second
 * later could answer for a placement that has since changed. They resolve the channels and hand
 * the slow half — the broadcast itself — to the `broadcasts` queue (TASK-170-003).
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
        TaskCreated::class => [RecordTaskCreated::class, BroadcastTaskChange::class],
        TaskUpdated::class => [RecordTaskUpdated::class, BroadcastTaskChange::class],
        TaskCompleted::class => [RecordTaskCompleted::class, BroadcastTaskChange::class],
        TaskReopened::class => [RecordTaskReopened::class, BroadcastTaskChange::class],
        TaskAssigned::class => [
            RecordTaskAssigned::class,
            NotifyAssignee::class,
            FollowAssignedTask::class,
            BroadcastTaskChange::class,
        ],
        TaskCollaboratorAdded::class => [
            RecordTaskCollaboratorAdded::class,
            NotifyNewCollaborator::class,
            FollowCollaboratedTask::class,
            BroadcastTaskChange::class,
        ],
        TaskCollaboratorRemoved::class => [RecordTaskCollaboratorRemoved::class, BroadcastTaskChange::class],

        // No activity for a deleted task — its history has nobody left to read it — but the
        // boards showing the card have to lose it.
        TaskDeleted::class => [BroadcastTaskChange::class],

        TaskAttachedToProject::class => [RecordTaskAttachedToProject::class, BroadcastPlacementChange::class],
        TaskDetachedFromProject::class => [RecordTaskDetachedFromProject::class, BroadcastPlacementChange::class],
        TaskPlacementMoved::class => [BroadcastPlacementChange::class],

        SectionCreated::class => [BroadcastSectionChange::class],
        SectionUpdated::class => [BroadcastSectionChange::class],
        SectionMoved::class => [BroadcastSectionChange::class],
        SectionDeleted::class => [BroadcastSectionChange::class],

        PageCreated::class => [BroadcastPageChange::class],
        PageUpdated::class => [BroadcastPageChange::class],
        PageMoved::class => [BroadcastPageChange::class],
        PageDeleted::class => [BroadcastPageChange::class],

        ProjectUpdated::class => [BroadcastProjectChange::class],
        ProjectArchived::class => [BroadcastProjectChange::class],

        // No activity for a comment — the feed reads that table directly — but the people
        // watching still have to hear about it.
        CommentCreated::class => [
            // Following first, so the author is watching before anybody is told about the
            // comment — and never notified about their own, which `NotifyWatchersOfComment`
            // already refuses.
            FollowCommentedTask::class,
            NotifyWatchersOfComment::class,
            NotifyMentionedPeople::class,
            BroadcastCommentChange::class,
        ],

        // A name added by an edit is somebody pulled into the thread all the same.
        CommentEdited::class => [NotifyMentionedPeople::class],

        // The one framework event in this map: an invitation sent to an address before it had
        // an account is waiting for the moment it does.
        Registered::class => [ClaimInvitationsForNewAccount::class],
    ];

    /**
     * Discovery would undo the point of the map above.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
