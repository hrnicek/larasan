<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Search\Queries\MessageResults;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * @return list<array<string, mixed>>
 */
function messageResults(Workspace $workspace, User $actor, string $term, int $limit = 5): array
{
    return app(MessageResults::class)($workspace, $actor, $term, $limit);
}

it('finds a message by what it says, and names the task it was said on', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);
    Comment::factory()->on($task)->create(['body' => '<p>The invoice went out twice</p>']);
    Comment::factory()->on($task)->create(['body' => '<p>Nothing to see</p>']);

    $results = messageResults($workspace, $actor, 'invoice');

    expect($results)->toHaveCount(1)
        ->and($results[0]['excerpt'])->toBe('The invoice went out twice')
        ->and($results[0]['task']['title'])->toBe('Fix the login screen');
})->with([
    'a comment on its own is a line with no context',
]);

it('never returns a message on a task the actor cannot reach', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $private = Project::factory()->in($workspace)->private()->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();
    Comment::factory()->on($task)->create(['body' => 'The invoice went out twice']);

    expect(messageResults($workspace, $actor, 'invoice'))->toBe([]);
})->with([
    'a comment is readable exactly when the thing it was said on is',
]);

it('stops returning a message the moment its project closes, without reindexing', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    Comment::factory()->on($task)->create(['body' => 'The invoice went out twice']);

    expect(messageResults($workspace, $actor, 'invoice'))->toHaveCount(1);

    Comment::withoutSyncingToSearch(fn () => $project->update(['visibility' => 'private']));

    expect(messageResults($workspace, $actor, 'invoice'))->toBe([]);
})->with([
    'reach is read at hydration, so a permission change lands before the index hears about it',
]);

it('never returns a message from another workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $elsewhere = Workspace::factory()->create();
    $task = Task::factory()->in($elsewhere)->create();
    Comment::factory()->on($task)->create(['body' => 'The invoice went out twice']);

    expect(messageResults($workspace, $actor, 'invoice'))->toBe([]);
});

it('never gives a guest a message on a task that sits in no project', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $task = Task::factory()->in($workspace)->create();
    Comment::factory()->on($task)->create(['body' => 'The invoice went out twice']);

    expect(messageResults($workspace, $guest, 'invoice'))->toBe([]);
});

it('says who said it and whether it was edited', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $author = memberOf($workspace, user: User::factory()->create(['name' => 'Jana Nováková', 'email' => 'jana@pinned.test']));
    $task = Task::factory()->in($workspace)->create();
    Comment::factory()->on($task)->by($author)->edited()->create(['body' => 'The invoice went out twice']);

    $results = messageResults($workspace, $actor, 'invoice');

    expect($results[0]['author']['name'])->toBe('Jana Nováková')
        ->and($results[0]['edited'])->toBeTrue();
});

it('returns nothing for an empty term', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create();
    Comment::factory()->on($task)->create(['body' => 'The invoice went out twice']);

    expect(messageResults($workspace, $actor, ''))->toBe([]);
});
