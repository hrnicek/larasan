<?php

declare(strict_types=1);

use App\Domain\Comment\Actions\CreateComment;
use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Notification\Queries\InboxQuery;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array{notifications: list<array<string, mixed>>, meta: array<string, mixed>}
 */
function inbox(Workspace $workspace, User $reader, int $page = 1, int $perPage = 25): array
{
    return app(InboxQuery::class)($workspace, $reader, $page, $perPage);
}

/**
 * A real assignment, so what the Inbox reads is what the domain writes.
 */
function assignTo(Workspace $workspace, User $actor, User $assignee, string $title = 'Fix login'): Task
{
    $task = Task::factory()->in($workspace)->create(['title' => $title]);

    app(AssignTask::class)->handle($task, $actor, $assignee);

    return $task;
}

it('reads what is waiting for one person here', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);

    $task = assignTo($workspace, $actor, $reader);

    $row = inbox($workspace, $reader)['notifications'][0];

    expect($row['type'])->toBe('task.assigned')
        ->and($row['read'])->toBeFalse()
        ->and($row['actor']['id'])->toBe($actor->id)
        ->and($row['subject'])->toBe(['type' => 'task', 'id' => $task->id, 'title' => 'Fix login']);
});

it('says what the task is called today, not when the notification was written', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);

    $task = assignTo($workspace, $actor, $reader, 'Old name');
    $task->forceFill(['title' => 'New name'])->save();

    // The row is rendered from the ids the notification kept, resolved now: a snapshot would
    // show a name nobody would recognise a week later.
    expect(inbox($workspace, $reader)['notifications'][0]['subject']['title'])->toBe('New name');
});

it('puts unread first and newest within that', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);

    $older = assignTo($workspace, $actor, $reader, 'Older unread');
    assignTo($workspace, $actor, $reader, 'Newer unread');
    $read = assignTo($workspace, $actor, $reader, 'Already read');

    /*
     * Written a minute apart on purpose. `created_at` is `timestamp(0)` and a notification's id
     * is a UUIDv4 — the framework generates it, not this application — so two notifications in
     * the same second have no meaningful order between them. The id still breaks the tie, which
     * is what keeps paging stable; it just does not mean "later".
     */
    DB::table('notifications')->where('data->task_id', $older->id)->update(['created_at' => now()->subMinute()]);

    DB::table('notifications')
        ->where('data->task_id', $read->id)
        ->update(['read_at' => now()]);

    $titles = array_map(
        fn (array $row): string => $row['subject']['title'],
        inbox($workspace, $reader)['notifications'],
    );

    // What still needs attention comes first; what has been dealt with stays readable below it.
    expect($titles)->toBe(['Newer unread', 'Older unread', 'Already read']);
});

it('never returns somebody else s notifications', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    $other = memberOf($workspace);

    assignTo($workspace, $actor, $reader, 'Mine');
    assignTo($workspace, $actor, $other, 'Theirs');

    expect(array_map(fn (array $row): string => $row['subject']['title'], inbox($workspace, $reader)['notifications']))
        ->toBe(['Mine']);
});

it('never returns the same person s notifications from another workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    memberOf($elsewhere, user: $reader);
    memberOf($elsewhere, user: $actor);

    assignTo($workspace, $actor, $reader, 'Here');
    assignTo($elsewhere, $actor, $reader, 'There');

    /*
     * The Inbox is per workspace: somebody in three of them should not have to read three
     * inboxes at once to find the thing they were told about.
     */
    expect(array_map(fn (array $row): string => $row['subject']['title'], inbox($workspace, $reader)['notifications']))
        ->toBe(['Here']);
});

it('counts what is still unread, in this workspace only', function (): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    memberOf($elsewhere, user: $reader);
    memberOf($elsewhere, user: $actor);

    assignTo($workspace, $actor, $reader);
    assignTo($workspace, $actor, $reader);
    assignTo($elsewhere, $actor, $reader);

    expect(inbox($workspace, $reader)['meta']['unread'])->toBe(2)
        ->and(app(InboxQuery::class)->unreadCount($elsewhere, $reader))->toBe(1);
});

it('reads a comment notification as its own kind', function (): void {
    [$workspace, , $author] = placeableProject();
    $reader = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();
    app(FollowTask::class)->handle($task, $reader);

    app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: 'Looks right to me'));

    $row = inbox($workspace, $reader)['notifications'][0];

    expect($row['type'])->toBe('comment.posted')
        ->and($row['actor']['id'])->toBe($author->id)
        ->and($row['subject']['id'])->toBe($task->id);
});

it('names a notification it does not recognise without showing a class name', function (): void {
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace);

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid7(),
        'workspace_id' => $workspace->id,
        'notifiable_type' => 'user',
        'notifiable_id' => $reader->id,
        'type' => 'App\\Notifications\\SomethingLater',
        'data' => json_encode([], JSON_THROW_ON_ERROR),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A class name is not something to show anybody, and a kind this query has not met yet is
    // still a line in somebody's inbox.
    $row = inbox($workspace, $reader)['notifications'][0];

    expect($row['type'])->toBe('unknown')
        ->and($row['subject'])->toBeNull();
});

it('pages the inbox', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);

    foreach (range(1, 7) as $index) {
        assignTo($workspace, $actor, $reader, "Task {$index}");
    }

    $first = inbox($workspace, $reader, perPage: 3);
    $last = inbox($workspace, $reader, page: 3, perPage: 3);

    expect($first['notifications'])->toHaveCount(3)
        ->and($first['meta']['total'])->toBe(7)
        ->and($first['meta']['hasMore'])->toBeTrue()
        ->and($last['notifications'])->toHaveCount(1)
        ->and($last['meta']['hasMore'])->toBeFalse();
});

it('reads a page of notifications from many people about many tasks in a fixed number of queries', function (): void {
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace);

    foreach (range(1, 10) as $index) {
        assignTo($workspace, memberOf($workspace), $reader, "Task {$index}");
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $result = inbox($workspace, $reader);

    /*
     * Ten notifications from ten people about ten tasks: the count, the page, the actors, the
     * subjects, and the unread count. A relation per row is at its worst here, where each line
     * points somewhere different.
     */
    expect($result['notifications'])->toHaveCount(10)
        ->and(count($queries))->toBeLessThanOrEqual(5);
});

it('has nothing to say when nothing has happened', function (): void {
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace);

    expect(inbox($workspace, $reader))->toBe([
        'notifications' => [],
        'meta' => ['page' => 1, 'perPage' => 25, 'total' => 0, 'hasMore' => false, 'unread' => 0],
    ]);
});
