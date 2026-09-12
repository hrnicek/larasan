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

        // No activity is recorded for comments: the feed reads the comments table directly.
        CommentCreated::class => [
            // Must run before the notifiers so the author is already following the task.
            FollowCommentedTask::class,
            NotifyWatchersOfComment::class,
            NotifyMentionedPeople::class,
            BroadcastCommentChange::class,
        ],

        CommentEdited::class => [NotifyMentionedPeople::class],

        Registered::class => [ClaimInvitationsForNewAccount::class],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
