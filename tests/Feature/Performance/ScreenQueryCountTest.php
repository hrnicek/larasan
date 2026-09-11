<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Domain\Notification\Notifications\TaskAssignedNotification;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * What each screen costs, measured rather than felt (TASK-180-003).
 *
 * The number itself is recorded so a later change can be compared against it, but the assertion
 * that matters is the second one: the same screen is measured against a workspace of one size and
 * a workspace of twice that size, and the counts must be **equal**. A query per row is invisible
 * on a laptop with ten rows and is the reason a real workspace stops loading.
 */

/**
 * A workspace with enough in it to expose a query per row: projects with sections, tasks placed
 * across them, tags, comments and a second member to render beside them.
 *
 * @return array{Workspace, User}
 */
function realisticWorkspace(int $tasks): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    /*
     * The colleagues grow with the tasks, and that is the point rather than realism for its own
     * sake: a query per row only shows up in a count when the number of *distinct* things each
     * row points at grows too. With one colleague, an inbox that resolved its actor per row would
     * still make one query, and this test would have proved nothing.
     */
    $colleagues = array_map(
        fn (): User => memberOf($workspace, WorkspaceRole::Member),
        range(1, max(2, intdiv($tasks, 3))),
    );

    $projects = Project::factory()->count(3)->in($workspace)->create();
    $tags = Tag::factory()->count(4)->in($workspace)->create();

    foreach ($projects as $project) {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess(ProjectAccessLevel::Editor)->create();
        foreach ([1, 2] as $slot) {
            Section::factory()->in($project)->at($slot * Section::POSITION_GAP)->create();
        }
    }

    $slots = [];

    foreach (range(1, $tasks) as $index) {
        $task = Task::factory()->in($workspace)->create([
            'title' => "Task {$index} about the login screen",
            'assignee_id' => $index % 2 === 0 ? $actor->id : $colleagues[$index % count($colleagues)]->id,
        ]);

        $project = $projects[$index % 3];
        $slots[$project->id] = ($slots[$project->id] ?? 0) + 1;

        TaskProjectMembership::factory()->placing($task, $project)->create([
            'section_id' => $project->sections()->value('id'),
            'position' => $slots[$project->id] * TaskProjectMembership::POSITION_GAP,
        ]);

        $task->tags()->attach($tags[$index % 4]->id);

        Comment::factory()->create([
            'workspace_id' => $workspace->id,
            'commentable_type' => 'task',
            'commentable_id' => $task->id,
            'author_id' => $colleagues[$index % count($colleagues)]->id,
        ]);

        // An inbox with nothing in it measures nothing, and the Inbox is a screen whose rows are
        // each built from ids resolved now — exactly the shape a query per row hides in.
        $actor->notify(new TaskAssignedNotification(
            $task->id,
            $workspace->id,
            $colleagues[$index % count($colleagues)]->id,
        ));
    }

    $actor->forceFill(['current_workspace_id' => $workspace->id])->save();

    return [$workspace, $actor];
}

/**
 * @return list<string>
 */
function queriesWhile(callable $work): array
{
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $work();

    return $queries;
}

/**
 * Where a screen lives, for a workspace that has just been filled.
 */
function screenUrl(string $screen, Workspace $workspace): string
{
    return match ($screen) {
        'board' => route('projects.show', [$workspace->projects()->first(), 'view' => 'board']),
        'list' => route('projects.show', [$workspace->projects()->first(), 'view' => 'list']),
        'projects' => route('projects.index'),
        'my-tasks' => route('my-tasks.index'),
        'inbox' => route('inbox.index'),
        'search' => route('search.index', ['q' => 'login']),
        'task' => route('tasks.show', Task::query()->where('workspace_id', $workspace->id)->firstOrFail()),
        default => throw new InvalidArgumentException("Unknown screen [{$screen}]."),
    };
}

it('costs the same whether a workspace holds twelve tasks or twice that', function (string $screen, int $budget): void {
    $counts = [];

    foreach ([12, 24] as $size) {
        [$workspace, $actor] = realisticWorkspace($size);
        $url = screenUrl($screen, $workspace);

        $counts[] = count(queriesWhile(function () use ($actor, $url): void {
            $this->actingAs($actor)->get($url)->assertOk();
        }));
    }

    [$small, $large] = $counts;

    expect($large)->toBe($small, "[{$screen}] made {$large} queries at double the size and {$small} at the original")
        ->and($large)->toBeLessThanOrEqual($budget, "[{$screen}] made {$large} queries, over its recorded budget of {$budget}");
})->with([
    // The budgets are what these screens actually cost today, recorded so a change can be
    // compared against them rather than argued about.
    //
    // The board and the list each gained one over Phase 200's numbers: opening a project is
    // remembered for the palette (TASK-210-008), which is an upsert and a trim, deferred until
    // after the response has gone out. Two statements, one of which the counter attributes to
    // the screen — bookkeeping nobody waits on, and the price of the palette being useful
    // before anybody types.
    //
    // And one more each in Phase 240: the header draws the project's people, so `projects.show`
    // reads them. Once, not twice — the faces and the count come from the same collection — and
    // it is a membership list rather than a list of work, so its size does not follow the
    // workspace's. Everything the *Share* dialog needs beyond it is `Inertia::optional` and
    // costs a visit that never opens it nothing.
    //
    // The board gains one more in Phase 250: the first image of each card's task, so a card can
    // draw a cover. One `DISTINCT ON` for the whole page — the figure is the same at twelve
    // tasks and at twenty-four, which is what this test is really asserting.
    //
    // The inbox gains one in Phase 290: each line names the projects its task lives in, the
    // ones the reader can open, in one read for the page. A comment's excerpt would be one more,
    // but this workspace's notifications are all assignments.
    //
    // The board and the list gain one in TASK-320-003, and it is a trade. The shell's props are
    // closures now, so a partial reload — a panel opening, a realtime refresh — no longer reads
    // the sidebar, the switcher and the badge only to throw them away. The sidebar used to run
    // before the screen, and its batched membership read happened to answer the screen's own
    // permission check; resolved after the screen, that check reads the project's membership
    // itself. One indexed row on a full visit, against the whole board on every panel opened
    // over it (`PartialReloadTest`).
    'the board' => ['board', 24],
    'the list' => ['list', 22],
    'the project list' => ['projects', 8],
    'my tasks' => ['my-tasks', 8],
    'the inbox' => ['inbox', 13],
    'search' => ['search', 14],
    /*
     * The most expensive screen in the application, and the one to watch: it renders placements,
     * followers, subtasks, custom fields, tags and attachments, each a read of its own.
     *
     * 30 → 31 with TASK-260-001. Editing a task now asks the boards it sits on and not only the
     * workspace, and the panel asks four such permissions; `TaskPolicy` memoises them within the
     * request, so the four cost one read each for the distinct questions and the page pays one
     * more than it did. It is a fixed cost — which is what this test asserts, by running the
     * same screen against twice the data.
     *
     * 31 → 32 with TASK-310-004: the people working beside the assignee are one read of their
     * own, however many there are.
     *
     * 32 → 34 with TASK-320-003, for the reason the board gains one: the sidebar's membership
     * read no longer runs before the page, so the page's permission checks read the memberships
     * they need themselves — two rows for this workspace, the same at either size.
     */
    'a task detail page' => ['task', 34],
]);
