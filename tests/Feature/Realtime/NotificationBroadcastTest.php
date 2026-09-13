<?php

declare(strict_types=1);

use App\Domain\Notification\Notifications\CommentPostedNotification;
use App\Domain\Notification\Notifications\TaskAssignedNotification;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;

/**
 * @return array{Workspace, User, Task}
 */
function inboxSubject(): array
{
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace, WorkspaceRole::Member);

    return [$workspace, $reader, Task::factory()->in($workspace)->create()];
}

it('sends a notification to the channel of the person it belongs to', function (): void {
    Event::fake([BroadcastNotificationCreated::class]);
    [$workspace, $reader, $task] = inboxSubject();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    $reader->notify(new TaskAssignedNotification($task->id, $workspace->id, $actor->id));

    Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $event) use ($reader): bool {
        expect($event->broadcastOn())->toEqual([new PrivateChannel("user.{$reader->id}")]);

        return true;
    });
});

it('queues a notification behind board updates rather than in front of them', function (): void {
    Event::fake([BroadcastNotificationCreated::class]);
    [$workspace, $reader, $task] = inboxSubject();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    $reader->notify(new TaskAssignedNotification($task->id, $workspace->id, $actor->id));

    Event::assertDispatched(
        BroadcastNotificationCreated::class,
        fn (BroadcastNotificationCreated $event): bool => $event->queue === 'notifications',
    );
});

it('carries the badge the shell draws, counted after the row was written', function (): void {
    Event::fake([BroadcastNotificationCreated::class]);
    [$workspace, $reader, $task] = inboxSubject();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    $reader->notify(new CommentPostedNotification(
        '01a00000-0000-7000-8000-000000000000',
        $task->id,
        $workspace->id,
        $actor->id,
    ));

    Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $event) use ($workspace): bool {
        expect($event->broadcastWith())
            ->toMatchArray([
                'kind' => 'comment.posted',
                'type' => 'notification.created',
                'workspaceId' => $workspace->id,
                'unread' => 1,
            ])
            ->toHaveKey('id');

        return true;
    });
});

it('counts only the workspace the notification came from', function (): void {
    Event::fake([BroadcastNotificationCreated::class]);
    [$workspace, $reader, $task] = inboxSubject();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    $elsewhere = Workspace::factory()->create();
    $otherTask = Task::factory()->in($elsewhere)->create();
    memberOf($elsewhere, WorkspaceRole::Member, user: $reader);
    $reader->notify(new TaskAssignedNotification($otherTask->id, $elsewhere->id, memberOf($elsewhere)->id));

    $reader->notify(new TaskAssignedNotification($task->id, $workspace->id, $actor->id));

    Event::assertDispatched(
        BroadcastNotificationCreated::class,
        fn (BroadcastNotificationCreated $event): bool => $event->broadcastWith()['workspaceId'] === $workspace->id
            && $event->broadcastWith()['unread'] === 1,
    );
});

it('names one event for every kind of notification', function (): void {
    Event::fake([BroadcastNotificationCreated::class]);
    [$workspace, $reader, $task] = inboxSubject();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    $reader->notify(new TaskAssignedNotification($task->id, $workspace->id, $actor->id));

    Event::assertDispatched(
        BroadcastNotificationCreated::class,
        fn (BroadcastNotificationCreated $event): bool => $event->broadcastType() === 'notification.created',
    );
});

it('still writes the row it always wrote', function (): void {
    Event::fake([BroadcastNotificationCreated::class]);
    [$workspace, $reader, $task] = inboxSubject();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    $reader->notify(new TaskAssignedNotification($task->id, $workspace->id, $actor->id));

    expect($reader->notifications()->count())->toBe(1);
});
