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

/**
 * @return array{Workspace, User}
 */
function realisticWorkspace(int $tasks): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    // Colleagues scale with tasks, otherwise a per-row lookup of distinct users would not show in the count.
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

        $actor->notify(new TaskAssignedNotification(
            $task->id,
            $workspace->id,
            $colleagues[$index % count($colleagues)]->id,
        ));
    }

    $actor->forceFill(['current_workspace_id' => $workspace->id])->save();

    return [$workspace, $actor];
}

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
    'the board' => ['board', 24],
    'the list' => ['list', 22],
    'the project list' => ['projects', 8],
    'my tasks' => ['my-tasks', 8],
    'the inbox' => ['inbox', 13],
    'search' => ['search', 14],
    'a task detail page' => ['task', 34],
]);
