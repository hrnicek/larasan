<?php

declare(strict_types=1);

use App\Domain\Comment\Actions\CreateComment;
use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Notification\Queries\InboxQuery;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\AddTaskCollaborator;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Actions\FollowTask;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('reads being put on a task beside its assignee, and who did it', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create(['title' => 'Ship it']);

    app(AddTaskCollaborator::class)->handle($task, $actor, $reader);

    $row = inbox($workspace, $reader)['notifications'][0];

    expect($row['type'])->toBe('task.collaborator_added')
        ->and($row['actor']['id'])->toBe($actor->id)
        ->and($row['subject']['id'])->toBe($task->id);
});

it('reads what is waiting for one person here', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);

    $task = assignTo($workspace, $actor, $reader);

    $row = inbox($workspace, $reader)['notifications'][0];

    expect($row['type'])->toBe('task.assigned')
        ->and($row['read'])->toBeFalse()
        ->and($row['actor']['id'])->toBe($actor->id)
        ->and($row['subject'])->toBe([
            'type' => 'task',
            'id' => $task->id,
            'title' => 'Fix login',
            'url' => route('tasks.show', $task->id),
            'projects' => [],
        ]);
});

it('says what the task is called today, not when the notification was written', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);

    $task = assignTo($workspace, $actor, $reader, 'Old name');
    $task->forceFill(['title' => 'New name'])->save();

    expect(inbox($workspace, $reader)['notifications'][0]['subject']['title'])->toBe('New name');
});

it('puts unread first and newest within that', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);

    $older = assignTo($workspace, $actor, $reader, 'Older unread');
    assignTo($workspace, $actor, $reader, 'Newer unread');
    $read = assignTo($workspace, $actor, $reader, 'Already read');

    // created_at is timestamp(0) and notification ids are UUIDv4, so same-second rows are unordered.
    DB::table('notifications')->where('data->task_id', $older->id)->update(['created_at' => now()->subMinute()]);

    DB::table('notifications')
        ->where('data->task_id', $read->id)
        ->update(['read_at' => now()]);

    $titles = array_map(
        fn (array $row): string => $row['subject']['title'],
        inbox($workspace, $reader)['notifications'],
    );

    expect($titles)->toBe(['Newer unread', 'Older unread', 'Already read']);
});

it("never returns somebody else's notifications", function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    $other = memberOf($workspace);

    assignTo($workspace, $actor, $reader, 'Mine');
    assignTo($workspace, $actor, $other, 'Theirs');

    expect(array_map(fn (array $row): string => $row['subject']['title'], inbox($workspace, $reader)['notifications']))
        ->toBe(['Mine']);
});

it("never returns the same person's notifications from another workspace", function (): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $reader = memberOf($workspace);
    memberOf($elsewhere, user: $reader);
    memberOf($elsewhere, user: $actor);

    assignTo($workspace, $actor, $reader, 'Here');
    assignTo($elsewhere, $actor, $reader, 'There');

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

    // The count, the page, the actors, the subjects, their projects, and the unread count.
    expect($result['notifications'])->toHaveCount(10)
        ->and(count($queries))->toBeLessThanOrEqual(6);
});

it('has nothing to say when nothing has happened', function (): void {
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace);

    expect(inbox($workspace, $reader))->toBe([
        'notifications' => [],
        'meta' => ['page' => 1, 'perPage' => 25, 'total' => 0, 'hasMore' => false, 'unread' => 0],
    ]);
});

it('gives a line no address and no name when the reader can no longer reach the task', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);
    $reader = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess(ProjectAccessLevel::Owner)->create();

    $task = assignTo($workspace, $actor, $reader, 'Still mine');
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $project->forceFill(['visibility' => ProjectVisibility::Private])->save();

    $subject = inbox($workspace, $reader)['notifications'][0]['subject'];

    expect($subject['id'])->toBeNull()
        ->and($subject['title'])->toBeNull()
        ->and($subject['url'])->toBeNull();
});

it('gives a guest an address for what they were given', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess(ProjectAccessLevel::Editor)->create();

    $task = Task::factory()->in($workspace)->create(['title' => 'Given']);
    TaskProjectMembership::factory()->placing($task, $project)->create();
    app(AssignTask::class)->handle($task, $actor, $guest);

    expect(inbox($workspace, $guest)['notifications'][0]['subject']['url'])
        ->toBe(route('tasks.show', $task->id));
});

it('gives a member an address for a task filed nowhere', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner);
    $reader = memberOf($workspace, WorkspaceRole::Member);

    $task = assignTo($workspace, $actor, $reader, 'Filed nowhere');

    expect(inbox($workspace, $reader)['notifications'][0]['subject']['url'])
        ->toBe(route('tasks.show', $task->id));
});
