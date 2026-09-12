<?php

declare(strict_types=1);

use App\Domain\Activity\Queries\TaskFeedQuery;
use App\Domain\Comment\Actions\CreateComment;
use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Comment\Models\Comment;
use App\Domain\Search\Queries\MessageResults;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return list<string|null>
 */
function mentionFeed(Task $task, User $viewer): array
{
    $entries = app(TaskFeedQuery::class)($task, $viewer)['entries'];

    return array_values(array_map(
        fn (array $entry): mixed => $entry['body'],
        array_filter($entries, fn (array $entry): bool => $entry['kind'] === 'comment'),
    ));
}

it('draws a mention with the name the person has now', function (): void {
    [$workspace, , $author] = placeableProject();
    $jana = memberOf($workspace, user: User::factory()->create(['name' => 'Jana Nováková']));
    $task = Task::factory()->in($workspace)->create();
    app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: 'Ask '.mentionOf($jana)));

    $jana->forceFill(['name' => 'Jana Svobodová'])->save();

    expect(mentionFeed($task, $author))->toBe(['Ask @[Jana Svobodová](user:'.$jana->id.')']);
});

it('never reads a stranger s name through a token nobody checked', function (): void {
    [$workspace, , $author] = placeableProject();
    $stranger = memberOf(Workspace::factory()->create(), user: User::factory()->create(['name' => 'Private Person']));
    $task = Task::factory()->in($workspace)->create();

    // Bypasses the Action, so the mention token was never validated.
    Comment::factory()->on($task)->by($author)->create(['body' => 'Hi @[Whoever](user:'.$stranger->id.')']);

    expect(mentionFeed($task, $author))->toBe(['Hi @[Whoever](user:'.$stranger->id.')']);
});

it('keeps the written name for somebody who has since left', function (): void {
    [$workspace, , $author] = placeableProject();
    $jana = memberOf($workspace, user: User::factory()->create(['name' => 'Jana Nováková']));
    $task = Task::factory()->in($workspace)->create();
    app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: 'Ask '.mentionOf($jana)));

    DB::table('workspace_memberships')
        ->where('workspace_id', $workspace->id)
        ->where('user_id', $jana->id)
        ->update(['status' => WorkspaceMembershipStatus::Revoked->value]);
    $jana->forceFill(['name' => 'Somebody Else Now'])->save();

    expect(mentionFeed($task, $author))->toBe(['Ask @[Jana Nováková](user:'.$jana->id.')']);
});

it('tells search the name rather than the token', function (): void {
    [$workspace, , $author] = placeableProject();
    $jana = memberOf($workspace, user: User::factory()->create(['name' => 'Jana Nováková']));
    $task = Task::factory()->in($workspace)->create();
    $comment = app(CreateComment::class)->handle($task, $author, new CreateCommentData(body: 'Ask '.mentionOf($jana)));

    expect($comment->toSearchableArray()['body'])->toBe('Ask @Jana Nováková');

    $results = app(MessageResults::class)($workspace, $author, 'Jana');

    expect($results)->toHaveCount(1)
        ->and($results[0]['excerpt'])->toBe('Ask @Jana Nováková');
});
