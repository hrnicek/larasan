<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Search\Queries\TaskResults;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * @return list<array<string, mixed>>
 */
function taskResults(Workspace $workspace, User $actor, string $term, int $limit = 5): array
{
    return app(TaskResults::class)($workspace, $actor, $term, $limit);
}

it('finds a task by its title', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);
    Task::factory()->in($workspace)->create(['title' => 'Write the changelog']);

    $results = taskResults($workspace, $actor, 'login');

    expect($results)->toHaveCount(1)
        ->and($results[0]['title'])->toBe('Fix the login screen');
});

it('finds a task by the words in its description, without its markup', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create([
        'title' => 'Nothing to do with it',
        'description' => '<p>The <strong>invoice</strong> is wrong</p>',
    ]);

    expect(taskResults($workspace, $actor, 'invoice'))->toHaveCount(1);
})->with([
    'p, li and strong are terms to a search engine, so an unstripped description makes "strong"
    return every task with a bold word in it',
]);

it('never returns a task from another workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $other = Workspace::factory()->create();
    Task::factory()->in($other)->create(['title' => 'Fix the login screen']);

    expect(taskResults($workspace, $actor, 'login'))->toBe([]);
});

it('never returns a task from a project the actor cannot open', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $private = Project::factory()->in($workspace)->private()->create();
    $task = Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);
    TaskProjectMembership::factory()->placing($task, $private)->create();

    expect(taskResults($workspace, $actor, 'login'))->toBe([]);
})->with([
    'a leak is invisible in a list nobody expected to be complete',
]);

it('never gives a guest a task that sits in no project', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $member = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);

    expect(taskResults($workspace, $guest, 'login'))->toBe([])
        ->and(taskResults($workspace, $member, 'login'))->toHaveCount(1);
})->with([
    'guests hold projects, and a task in none was never given to them',
]);

it('names only the projects the reader may open', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $open = Project::factory()->in($workspace)->create(['name' => 'Website']);
    $private = Project::factory()->in($workspace)->private()->create(['name' => 'Acquisition']);
    $task = Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);
    TaskProjectMembership::factory()->placing($task, $open)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();

    $results = taskResults($workspace, $actor, 'login');

    expect($results)->toHaveCount(1)
        ->and($results[0]['projects'])->toHaveCount(1)
        ->and($results[0]['projects'][0]['name'])->toBe('Website');
})->with([
    'a task reached through one project must not name another the reader has never seen',
]);

it('returns nothing for an empty term', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);

    expect(taskResults($workspace, $actor, '   '))->toBe([]);
})->with([
    '"everything" is the one answer nobody typed a search box to get',
]);

it('stops at the limit it was given', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->count(6)->in($workspace)->create(['title' => 'Login work']);

    expect(taskResults($workspace, $actor, 'login', 3))->toHaveCount(3);
});

it('ships the fields a palette row draws and nothing more', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);

    expect(array_keys(taskResults($workspace, $actor, 'login')[0]))
        ->toEqualCanonicalizing(['id', 'title', 'dueAt', 'completedAt', 'priority', 'assignee', 'projects']);
})->with([
    'a prop the screen does not draw is a column somebody added to the table by accident',
]);
