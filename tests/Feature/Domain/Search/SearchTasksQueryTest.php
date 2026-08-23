<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Search\Queries\SearchTasksQuery;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * @return list<string>
 */
function found(Workspace $workspace, User $actor, string $term, array $filters = []): array
{
    return array_column(app(SearchTasksQuery::class)($workspace, $actor, $term, 1, $filters)['tasks'], 'title');
}

it('finds a task by a word in its title', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);
    Task::factory()->in($workspace)->create(['title' => 'Write the changelog']);

    expect(found($workspace, $actor, 'login'))->toBe(['Fix the login screen']);
});

it('finds a task by a word in its description', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    Task::factory()->in($workspace)->create(['title' => 'Something', 'description' => 'The invoice is wrong']);

    expect(found($workspace, $actor, 'invoice'))->toBe(['Something']);
});

it('finds a word from its beginning', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);

    // People type `log` expecting `login`, and a search that answers as you type has to keep up
    // (ADR-0012).
    expect(found($workspace, $actor, 'log'))->toBe(['Fix the login screen']);
});

it('finds a word somebody spelled without its accents', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    Task::factory()->in($workspace)->create(['title' => 'Ask Hrnčíř about the invoice']);

    // Half the keyboards in this application's first market do not have the accents on them.
    expect(found($workspace, $actor, 'hrncir'))->toBe(['Ask Hrnčíř about the invoice']);
});

it('needs every word, not any of them', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);
    Task::factory()->in($workspace)->create(['title' => 'Fix the invoice']);

    expect(found($workspace, $actor, 'fix login'))->toBe(['Fix the login screen']);
});

it('returns nothing for an empty term', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Something']);

    /*
     * "Everything" is the one answer nobody typed a search box to get, and on a workspace of any
     * size it is also the most expensive.
     */
    expect(found($workspace, $actor, ''))->toBe([])
        ->and(found($workspace, $actor, '   '))->toBe([]);
});

it('survives punctuation somebody typed', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);

    // `&`, `!` and a stray quote are all operators to `to_tsquery`, and a search box is not a
    // place to learn that.
    expect(found($workspace, $actor, 'login & !'))->toBe(['Fix the login screen'])
        ->and(found($workspace, $actor, "'"))->toBe([]);
});

it('never crosses a workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace);
    memberOf($elsewhere, user: $actor);

    Task::factory()->in($workspace)->create(['title' => 'Ours: login']);
    Task::factory()->in($elsewhere)->create(['title' => 'Theirs: login']);

    expect(found($workspace, $actor, 'login'))->toBe(['Ours: login']);
});

it('never returns a task in a project the actor was not given', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);

    $hidden = Task::factory()->in($workspace)->create(['title' => 'Secret login work']);
    TaskProjectMembership::factory()->placing($hidden, $private)->create();

    /*
     * The leak this whole query is shaped around: a row that should not be there looks like a
     * feature, and nobody reports it.
     */
    expect(found($workspace, $actor, 'login'))->toBe([]);
});

it('returns a private project s task to somebody who was given it', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($private)->forUser($actor)->withAccess(ProjectAccessLevel::Viewer)->create();

    $task = Task::factory()->in($workspace)->create(['title' => 'Given login work']);
    TaskProjectMembership::factory()->placing($task, $private)->create();

    expect(found($workspace, $actor, 'login'))->toBe(['Given login work']);
});

it('returns a task filed nowhere to a member and not to a guest', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    Task::factory()->in($workspace)->create(['title' => 'Loose login work']);

    // Workspace work is a member's to find; a guest holds projects, and a task in none was never
    // given to them.
    expect(found($workspace, $member, 'login'))->toBe(['Loose login work'])
        ->and(found($workspace, $guest, 'login'))->toBe([]);
});

it('names only the projects the reader can reach', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $open = Project::factory()->in($workspace)->create(['name' => 'Open']);
    $private = Project::factory()->in($workspace)->create(['name' => 'Private', 'visibility' => ProjectVisibility::Private]);

    $task = Task::factory()->in($workspace)->create(['title' => 'Shared login work']);
    TaskProjectMembership::factory()->placing($task, $open)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();

    $result = app(SearchTasksQuery::class)($workspace, $actor, 'login');

    expect(array_column($result['tasks'][0]['projects'], 'name'))->toBe(['Open']);
});

it('filters by project, assignee and completion', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $assignee = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();

    $mine = Task::factory()->in($workspace)->create(['title' => 'Login one', 'assignee_id' => $assignee->id]);
    TaskProjectMembership::factory()->placing($mine, $project)->create();

    Task::factory()->in($workspace)->create(['title' => 'Login two', 'completed_at' => now()]);
    Task::factory()->in($workspace)->create(['title' => 'Login three']);

    expect(found($workspace, $actor, 'login', ['project' => $project->id]))->toBe(['Login one'])
        ->and(found($workspace, $actor, 'login', ['assignee' => $assignee->id]))->toBe(['Login one'])
        ->and(found($workspace, $actor, 'login', ['completed' => true]))->toBe(['Login two'])
        ->and(count(found($workspace, $actor, 'login', ['completed' => false])))->toBe(2);
});

it('pages the results', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    foreach (range(1, 7) as $index) {
        Task::factory()->in($workspace)->create(['title' => "Login task {$index}"]);
    }

    $first = app(SearchTasksQuery::class)($workspace, $actor, 'login', 1, [], 3);
    $last = app(SearchTasksQuery::class)($workspace, $actor, 'login', 3, [], 3);

    expect($first['tasks'])->toHaveCount(3)
        ->and($first['meta']['total'])->toBe(7)
        ->and($first['meta']['hasMore'])->toBeTrue()
        ->and($last['tasks'])->toHaveCount(1)
        ->and($last['meta']['hasMore'])->toBeFalse();
});

it('reads a page of results without a query per row', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();

    foreach (range(1, 10) as $index) {
        $task = Task::factory()->in($workspace)->create([
            'title' => "Login task {$index}",
            'assignee_id' => memberOf($workspace)->id,
        ]);
        TaskProjectMembership::factory()->placing($task, $project)->at($index * SparsePosition::GAP)->create();
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $result = app(SearchTasksQuery::class)($workspace, $actor, 'login');

    expect($result['tasks'])->toHaveCount(10)
        ->and(count($queries))->toBeLessThanOrEqual(8);
});
