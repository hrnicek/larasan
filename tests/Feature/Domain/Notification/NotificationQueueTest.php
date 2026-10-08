<?php

declare(strict_types=1);

use App\Domain\Activity\Listeners\RecordTaskAssigned;
use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Comment\Events\CommentEdited;
use App\Domain\Notification\Listeners\NotifyAssignee;
use App\Domain\Notification\Listeners\NotifyMentionedPeople;
use App\Domain\Notification\Listeners\NotifyNewCollaborator;
use App\Domain\Notification\Listeners\NotifyWatchersOfComment;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Events\TaskCollaboratorAdded;
use App\Domain\Task\Models\Task;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function assertQueued(string $listener, string $queue): void
{
    Queue::assertPushed(
        CallQueuedListener::class,
        fn (CallQueuedListener $job, ?string $pushedTo): bool => $job->class === $listener && $pushedTo === $queue,
    );
}

it('sends notification work to the notifications queue', function (): void {
    Queue::fake();

    event(new TaskAssigned((string) Str::uuid7(), (string) Str::uuid7(), 2, 1));

    // Queued below broadcasts so a slow inbox never delays a board update. See ADR-0008.
    assertQueued(NotifyAssignee::class, 'notifications');
});

it('queues the notice to a new collaborator beside it', function (): void {
    Queue::fake();

    event(new TaskCollaboratorAdded((string) Str::uuid7(), (string) Str::uuid7(), 2, 1));

    assertQueued(NotifyNewCollaborator::class, 'notifications');
});

it('queues the comment notifications too', function (): void {
    Queue::fake();

    event(new CommentCreated((string) Str::uuid7(), (string) Str::uuid7(), 'task', (string) Str::uuid7(), 1));

    assertQueued(NotifyWatchersOfComment::class, 'notifications');
});

it('queues the mention notifications, on a write and on an edit', function (): void {
    Queue::fake();
    event(new CommentCreated((string) Str::uuid7(), (string) Str::uuid7(), 'task', (string) Str::uuid7(), 1, [2]));
    assertQueued(NotifyMentionedPeople::class, 'notifications');

    Queue::fake();
    event(new CommentEdited((string) Str::uuid7(), (string) Str::uuid7(), 'task', (string) Str::uuid7(), 1, [2]));
    assertQueued(NotifyMentionedPeople::class, 'notifications');
});

it('keeps recording history on the request that caused it', function (): void {
    Queue::fake();

    event(new TaskAssigned((string) Str::uuid7(), (string) Str::uuid7(), 2, 1));

    Queue::assertNotPushed(
        CallQueuedListener::class,
        fn (CallQueuedListener $job): bool => $job->class === RecordTaskAssigned::class,
    );
});

it('does not deliver a notification before the work it describes is committed', function (): void {
    expect(config('queue.connections.redis.after_commit'))->toBeTrue();
});

it('still writes the notification when the queue runs it', function (): void {
    [$workspace, , $actor] = placeableProject();
    $assignee = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();

    // The test suite uses the sync queue, so the queued listener runs inline.
    app(AssignTask::class)->handle($task, $actor, $assignee);

    expect(DB::table('notifications')->count())->toBe(1);
});
