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
});

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
});

it('keeps the timeout chain in the order that stops a job running twice', function (): void {
    // A job still running when retry_after expires is handed to a second worker.
    $worker = config('horizon.defaults.supervisor-1.timeout');
    $retryAfter = config('queue.connections.redis.retry_after');

    expect(10)->toBeLessThan($worker)
        ->and($worker)->toBeLessThan($retryAfter);
});

it('supervises the queues in the order ADR-0008 states', function (): void {
    expect(config('horizon.defaults.supervisor-1.queue'))
        ->toBe(['broadcasts', 'notifications', 'search', 'media', 'default']);
});

it('waits for the transaction before a subscriber can read the row', function (): void {
    expect(config('queue.connections.redis.after_commit'))->toBeTrue();
});
