<?php

declare(strict_types=1);

use App\Domain\Comment\Events\CommentCreated;
use App\Domain\Notification\Listeners\NotifyAssignee;
use App\Domain\Notification\Listeners\NotifyWatchersOfComment;
use App\Domain\Notification\Notifications\TaskAssignedNotification;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Events\TaskAssigned;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskFollower;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Queued listeners are retried, so each test runs a listener twice for the same event.

it('writes one line however many times the listener runs', function (): void {
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace, WorkspaceRole::Member);
    $watcher = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create();
    TaskFollower::factory()->create(['task_id' => $task->id, 'user_id' => $watcher->id]);

    $event = new CommentCreated((string) Str::uuid7(), $workspace->id, 'task', $task->id, $author->id);

    app(NotifyWatchersOfComment::class)->handle($event);
    app(NotifyWatchersOfComment::class)->handle($event);

    expect($watcher->notifications()->count())->toBe(1);
});

it('writes one line for an assignment however many times the listener runs', function (): void {
    $workspace = Workspace::factory()->create();
    $assigner = memberOf($workspace, WorkspaceRole::Member);
    $assignee = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create(['assignee_id' => $assignee->id]);

    $event = new TaskAssigned($task->id, $workspace->id, $assignee->id, $assigner->id);

    app(NotifyAssignee::class)->handle($event);
    app(NotifyAssignee::class)->handle($event);

    expect($assignee->notifications()->count())->toBe(1);
});

it('still tells each person watching, once each', function (): void {
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create();

    foreach (range(1, 3) as $ignored) {
        TaskFollower::factory()->create([
            'task_id' => $task->id,
            'user_id' => memberOf($workspace, WorkspaceRole::Member)->id,
        ]);
    }

    $event = new CommentCreated((string) Str::uuid7(), $workspace->id, 'task', $task->id, $author->id);

    app(NotifyWatchersOfComment::class)->handle($event);
    app(NotifyWatchersOfComment::class)->handle($event);

    expect(DB::table('notifications')->count())->toBe(3);
});

it('lets two different comments on one task through', function (): void {
    $workspace = Workspace::factory()->create();
    $author = memberOf($workspace, WorkspaceRole::Member);
    $watcher = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create();
    TaskFollower::factory()->create(['task_id' => $task->id, 'user_id' => $watcher->id]);

    foreach ([1, 2] as $ignored) {
        app(NotifyWatchersOfComment::class)->handle(
            new CommentCreated((string) Str::uuid7(), $workspace->id, 'task', $task->id, $author->id),
        );
    }

    expect($watcher->notifications()->count())->toBe(2);
});

it('refuses a second row at the database, not only in the channel', function (): void {
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create();

    $reader->notify(new TaskAssignedNotification($task->id, $workspace->id, $reader->id));

    $row = (array) DB::table('notifications')->where('notifiable_id', $reader->id)->firstOrFail();

    expect(fn () => DB::transaction(fn () => DB::table('notifications')->insert([
        ...$row,
        'id' => (string) Str::uuid7(),
    ])))->toThrow(QueryException::class);

    expect(DB::table('notifications')->count())->toBe(1);
});
