<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\ProjectBoardQuery;
use App\Domain\Project\Queries\ProjectListQuery;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * A card in the project, with the given tags on it.
 *
 * @param  list<Tag>  $tags
 */
function taggedCard(Project $project, User $actor, string $title, array $tags, int $slot): Task
{
    $task = Task::factory()->in($project->workspace)->create(['title' => $title]);
    TaskProjectMembership::factory()->placing($task, $project)->at($slot * SparsePosition::GAP)->create();

    foreach ($tags as $tag) {
        tagTask($task, $tag, $actor);
    }

    return $task;
}

it('filters a list to the cards carrying every tag', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $bug = Tag::factory()->in($workspace)->named('Bug')->create();
    $urgent = Tag::factory()->in($workspace)->named('Urgent')->create();

    taggedCard($project, $actor, 'Both', [$bug, $urgent], 1);
    taggedCard($project, $actor, 'Only bug', [$bug], 2);
    taggedCard($project, $actor, 'Neither', [], 3);

    /*
     * All rather than any: a filter that widened as you added terms would be the opposite of
     * what picking a second tag means.
     */
    $list = app(ProjectListQuery::class)($project, $actor, [$bug->id, $urgent->id]);

    expect(array_column($list['sections'][0]['tasks'], 'title'))->toBe(['Both']);
});

it('filters a board and its counts together', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $bug = Tag::factory()->in($workspace)->named('Bug')->create();

    taggedCard($project, $actor, 'Tagged', [$bug], 1);
    taggedCard($project, $actor, 'Untagged', [], 2);

    // A column's header must not disagree with its contents: counting and rendering go through
    // the same scope.
    $board = app(ProjectBoardQuery::class)($project, $actor, [], [$bug->id]);

    expect(array_column($board['columns'][0]['tasks'], 'title'))->toBe(['Tagged'])
        ->and($board['columns'][0]['count'])->toBe(1);
});

it('treats no tags as no filter', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $bug = Tag::factory()->in($workspace)->create();

    taggedCard($project, $actor, 'Tagged', [$bug], 1);
    taggedCard($project, $actor, 'Untagged', [], 2);

    // An empty list is no filter at all rather than a filter nothing matches.
    expect(app(ProjectListQuery::class)($project, $actor, [])['sections'][0]['tasks'])->toHaveCount(2);
});

it('matches nothing for a tag that is not on anything', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $unused = Tag::factory()->in($workspace)->create();

    taggedCard($project, $actor, 'Untagged', [], 1);

    // The ungrouped bucket appears only when it holds something, so a filter that matches
    // nothing leaves the screen with no groups at all rather than an empty one.
    expect(app(ProjectListQuery::class)($project, $actor, [$unused->id])['sections'])->toBe([]);
});

it('reads a filtered board without a query per card', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $bug = Tag::factory()->in($workspace)->create();

    foreach (range(1, 12) as $index) {
        taggedCard($project, $actor, "Task {$index}", [$bug], $index);
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $board = app(ProjectBoardQuery::class)($project, $actor, [], [$bug->id]);

    // The filter is a condition on the reads the board already makes, not a read per card.
    expect($board['columns'][0]['tasks'])->toHaveCount(12)
        ->and($board['columns'][0]['count'])->toBe(12)
        ->and(count($queries))->toBeLessThanOrEqual(8);
});

it('carries the filter in the URL and echoes back what it understood', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $bug = Tag::factory()->in($workspace)->named('Bug')->create();
    taggedCard($project, $actor, 'Tagged', [$bug], 1);
    taggedCard($project, $actor, 'Untagged', [], 2);

    $this->actingAs($actor)
        ->get(route('projects.show', [$project, 'view' => ProjectDefaultView::List->value, 'tags' => [$bug->id]]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('tags.active', [$bug->id])
            ->has('tags.available', 1)
            ->where('list.sections.0.tasks.0.title', 'Tagged')
            ->has('list.sections.0.tasks', 1));
});

it('renders a board rather than an error when a link names a tag that is gone', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $tag = Tag::factory()->in($workspace)->create();
    taggedCard($project, $actor, 'Tagged', [$tag], 1);
    $id = $tag->id;
    $tag->delete();

    /*
     * A 404 for a deleted tag would throw away a board somebody can still read. The filter
     * matches nothing, which is the honest answer to a stale link.
     */
    $this->actingAs($actor)
        ->get(route('projects.show', [$project, 'view' => ProjectDefaultView::List->value, 'tags' => [$id]]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('list.sections', []));
});

it('refuses a filter that is not a list of ids', function (): void {
    [, $project, $actor] = placeableProject();

    $this->actingAs($actor)
        ->get(route('projects.show', [$project, 'tags' => ['not-a-uuid']]))
        ->assertSessionHasErrors('tags.0');
});
