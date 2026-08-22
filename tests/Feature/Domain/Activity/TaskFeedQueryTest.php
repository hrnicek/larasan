<?php

declare(strict_types=1);

use App\Domain\Activity\Models\Activity;
use App\Domain\Activity\Queries\TaskFeedQuery;
use App\Domain\Comment\Models\Comment;
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

    // Newest first: a long thread is read from its end, and "load older" is the direction
    // people scroll. The screen reverses a page to draw it.
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

    /*
     * Merging two paginated lists in PHP would give a page that is neither table's page, and a
     * thread that skips lines as soon as it is longer than one screen. Twelve lines, four at a
     * time, and every one of them appears exactly once.
     */
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

    expect($entry['actor'])->toBe(['id' => $author->id, 'name' => $author->name, 'email' => $author->email]);
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

    /*
     * A feed is the one screen where every line has a different person on it, so a lazy
     * relation here is an N+1 per page. Four reads: the count, the page, the actors, and the
     * one membership lookup that answers the page's permissions.
     */
    expect($feed['entries'])->toHaveCount(10)
        ->and(count($queries))->toBe(4);
});

it('keeps a removed comment in the thread and its words out of it', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $comment = Comment::factory()->on($task)->create(['body' => 'Something regrettable']);
    $comment->delete();

    $entry = feedOf($task)['entries'][0];

    // The line stays so the feed can say a comment was removed rather than closing the gap and
    // changing what the conversation appears to say — and "removed" is not a place the words
    // are still readable.
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

    /*
     * A row that names the right subject and the wrong workspace should not be readable. It
     * cannot arrive through the domain, which is exactly why the query asks rather than
     * trusting the id it was handed (ADR-0005).
     */
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

    /*
     * Reach is settled by the time somebody is reading this feed, so the query answers the two
     * questions that are left — authorship and `comment.delete` — instead of loading a model
     * per line to ask the policy again. These assertions are what keeps the two answers the
     * same one.
     */
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

    // An activity is a record of something that already happened, and a removed comment is no
    // longer part of the conversation.
    $entries = feedOf($task, $author)['entries'];

    expect(array_column($entries, 'canEdit'))->toBe([false, false])
        ->and(array_column($entries, 'canDelete'))->toBe([false, false]);
});
