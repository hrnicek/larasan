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
    'the board' => ['board', 21],
    'the list' => ['list', 20],
    'the project list' => ['projects', 8],
    'my tasks' => ['my-tasks', 8],
    'the inbox' => ['inbox', 12],
    'search' => ['search', 14],
    // The most expensive screen in the application, and the one to watch: it renders placements,
    // followers, subtasks, custom fields, tags and attachments, each a read of its own.
    'a task detail page' => ['task', 30],
]);
