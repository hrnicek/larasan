<?php

declare(strict_types=1);

use App\Domain\Notification\Notifications\TaskAssignedNotification;
use App\Domain\Shared\Broadcasting\ViewInvalidated;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Events\TaskUpdated;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Queue;

it('puts a board update on the broadcasts queue', function (): void {
    Queue::fake();
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create();

    event(new TaskUpdated($task->id, $workspace->id, ['title'], $actor->id));

    Queue::assertPushedOn('broadcasts', BroadcastEvent::class);
})->with([
    'asserted through the queue rather than by reading the method, because a property nobody
    pushed is a property nobody proved',
]);

it('puts a notification on the notifications queue, behind board updates', function (): void {
    Queue::fake();
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace, WorkspaceRole::Member);
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create();

    $reader->notify(new TaskAssignedNotification($task->id, $workspace->id, $actor->id));

    Queue::assertPushedOn('notifications', BroadcastEvent::class);
});

it('bounds what a failing broadcast may cost', function (): void {
    $reflection = new ReflectionClass(ViewInvalidated::class);

    $attribute = fn (string $class): object => $reflection->getAttributes($class)[0]->newInstance();

    expect($attribute(Tries::class))->toHaveProperty('tries', 3)
        ->and($attribute(Backoff::class))->toHaveProperty('backoff', 2)
        ->and($attribute(Timeout::class))->toHaveProperty('timeout', 10);
})->with([
    'a board update delivered a minute late is worse than one that never arrives, because the
    client has moved on and ADR-0008 recovers a missed event by asking the server',
]);

it('keeps the timeout chain in the order that stops a job running twice', function (): void {
    $worker = config('horizon.defaults.supervisor-1.timeout');
    $retryAfter = config('queue.connections.redis.retry_after');

    expect(10)->toBeLessThan($worker)
        ->and($worker)->toBeLessThan($retryAfter);
})->with([
    'a job that outlives retry_after is handed to a second worker while the first is still
    running it',
]);

it('supervises the queues in the order ADR-0008 states', function (): void {
    expect(config('horizon.defaults.supervisor-1.queue'))
        ->toBe(['broadcasts', 'notifications', 'search', 'default']);
})->with([
    'a slow email must never be what delayed a board update, and neither must a reindex',
]);

it('waits for the transaction before a subscriber can read the row', function (): void {
    expect(config('queue.connections.redis.after_commit'))->toBeTrue();
})->with([
    'a broadcast queued inside a transaction that then rolls back describes a row nobody has',
]);
