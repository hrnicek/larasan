<?php

declare(strict_types=1);

use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Queries\TaskFeedQuery;
use App\Domain\Comment\Models\Comment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ActivityType;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * @return array{entries: list<array<string, mixed>>, meta: array{page: int, perPage: int, total: int, hasMore: bool}}
 */
function feedOf(Task $task, ?User $viewer = null, int $page = 1, int $perPage = 30): array
{
    $viewer ??= memberOf($task->workspace);

    return app(TaskFeedQuery::class)($task, $viewer, $page, $perPage);
}

it('interleaves comments and activities by time', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    Comment::factory()->on($task)->create(['body' => 'First', 'created_at' => now()->subMinutes(4)]);
    Activity::factory()->on($task)->ofType(ActivityType::TaskCompleted)->create(['created_at' => now()->subMinutes(3)]);
    Comment::factory()->on($task)->create(['body' => 'Second', 'created_at' => now()->subMinutes(2)]);
    Activity::factory()->on($task)->ofType(ActivityType::TaskReopened)->create(['created_at' => now()->subMinute()]);

    // Newest first.
    expect(array_column(feedOf($task)['entries'], 'kind'))
        ->toBe(['activity', 'comment', 'activity', 'comment']);
});

it('paginates one thread rather than two lists', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    foreach (range(1, 6) as $minute) {
        Comment::factory()->on($task)->create(['created_at' => now()->subMinutes($minute * 2)]);
        Activity::factory()->on($task)->create(['created_at' => now()->subMinutes($minute * 2 + 1)]);
    }

    $seen = [];

    foreach (range(1, 3) as $page) {
        $feed = feedOf($task, page: $page, perPage: 4);

        expect($feed['entries'])->toHaveCount(4)
            ->and($feed['meta']['total'])->toBe(12)
            ->and($feed['meta']['hasMore'])->toBe($page < 3);

        $seen = [...$seen, ...array_column($feed['entries'], 'id')];
    }

    expect($seen)->toHaveCount(12)
        ->and(array_unique($seen))->toHaveCount(12);
});

it('carries the actor of each line', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $author = memberOf($workspace);

    Comment::factory()->on($task)->by($author)->create();

    $entry = feedOf($task)['entries'][0];

    expect($entry['actor'])->toBe(['id' => $author->id, 'name' => $author->name, 'email' => $author->email, 'avatar' => null]);
});

it('reads every actor on a page in one query', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    foreach (range(1, 10) as $minute) {
        Comment::factory()->on($task)->by(memberOf($workspace))->create(['created_at' => now()->subMinutes($minute)]);
    }

    $viewer = memberOf($workspace);
    $task->loadMissing('workspace');

    DB::enableQueryLog();
    $feed = feedOf($task, $viewer);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // The count, the page, the actors, and one membership lookup for the page's permissions.
    expect($feed['entries'])->toHaveCount(10)
        ->and(count($queries))->toBe(4);
});

it('keeps a removed comment in the thread and its words out of it', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $comment = Comment::factory()->on($task)->create(['body' => 'Something regrettable']);
    $comment->delete();

    $entry = feedOf($task)['entries'][0];

    expect($entry['deleted'])->toBeTrue()
        ->and($entry['body'])->toBeNull();
});

it('says which comments were edited', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    Comment::factory()->on($task)->edited()->create(['created_at' => now()->subMinute()]);
    Comment::factory()->on($task)->create();

    expect(array_column(feedOf($task)['entries'], 'edited'))->toBe([false, true]);
});

it('hands back an activity s properties as data rather than a string', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    Activity::factory()->on($task)->ofType(ActivityType::TaskUpdated, ['changed' => ['title']])->create();

    $entry = feedOf($task)['entries'][0];

    expect($entry['type'])->toBe('task.updated')
        ->and($entry['properties'])->toBe(['changed' => ['title']]);
});

it('keeps another task s thread out', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $other = Task::factory()->in($workspace)->create();

    Comment::factory()->on($task)->create(['body' => 'Mine']);
    Comment::factory()->on($other)->create(['body' => 'Theirs']);
    Activity::factory()->on($other)->create();

    expect(array_column(feedOf($task)['entries'], 'body'))->toBe(['Mine']);
});

it('proves its own workspace scope', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    // Inserted raw: the query must check the workspace rather than trust the subject id. See ADR-0005.
    $elsewhere = Workspace::factory()->create();

    DB::table('comments')->insert([
        'id' => (string) Str::uuid7(),
        'workspace_id' => $elsewhere->id,
        'commentable_type' => 'task',
        'commentable_id' => $task->id,
        'author_id' => null,
        'body' => 'Smuggled',
        'edited_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(feedOf($task)['entries'])->toBe([]);
});

it('has nothing to say about a task nothing has happened to', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    expect(feedOf($task))->toBe([
        'entries' => [],
        'meta' => ['page' => 1, 'perPage' => 30, 'total' => 0, 'hasMore' => false],
    ]);
});

it('sends the permissions the thread renders, and they match what the policy answers', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $author = memberOf($workspace, WorkspaceRole::Member);
    $moderator = memberOf($workspace, WorkspaceRole::Admin);
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $comment = Comment::factory()->on($task)->by($author)->create();

    // The query derives these flags without the policy, so they are checked against it here.
    foreach ([$author, $moderator, $guest] as $viewer) {
        $entry = feedOf($task, $viewer)['entries'][0];

        expect($entry['canEdit'])->toBe(Gate::forUser($viewer)->allows('update', $comment))
            ->and($entry['canDelete'])->toBe(Gate::forUser($viewer)->allows('delete', $comment));
    }
});

it('offers nothing to change on an activity or on a removed comment', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $author = memberOf($workspace, WorkspaceRole::Member);
    Activity::factory()->on($task)->by($author)->create(['created_at' => now()->subMinute()]);
    $comment = Comment::factory()->on($task)->by($author)->create();
    $comment->delete();

    $entries = feedOf($task, $author)['entries'];

    expect(array_column($entries, 'canEdit'))->toBe([false, false])
        ->and(array_column($entries, 'canDelete'))->toBe([false, false]);
});

it('draws each line with its author\'s face, not only their initials', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $author = memberOf($workspace, user: User::factory()->withAvatarPreset(6)->create());

    Comment::factory()->on($task)->create(['author_id' => $author->id]);

    expect(feedOf($task)['entries'][0]['actor'])->toBe([
        'id' => $author->id,
        'name' => $author->name,
        'email' => $author->email,
        'avatar' => asset('img/avatars/6.svg'),
    ]);
});

it('offers no edit to an author who may no longer comment on the task', function (): void {
    $workspace = Workspace::factory()->create();
    $project = Project::factory()->in($workspace)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $author = viewerOf($project);
    $comment = Comment::factory()->on($task)->by($author)->create();

    $entry = feedOf($task, $author)['entries'][0];

    expect($entry['canEdit'])->toBeFalse()
        ->and(Gate::forUser($author)->allows('update', $comment))->toBeFalse();
});
