<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\TaskDetailQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, mixed>
 */
function detailOf(Task $task, User $actor): array
{
    return app(TaskDetailQuery::class)($task->fresh() ?? $task, $actor);
}

it('carries the task and the people on it', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $assignee = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create([
        'title' => 'Write it down',
        'description' => 'Somewhere it can be found again',
        'priority' => TaskPriority::High,
        'due_at' => now()->addDay(),
        'assignee_id' => $assignee->id,
        'created_by' => $actor->id,
    ]);
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $detail = detailOf($task, $actor);

    expect($detail['task']['title'])->toBe('Write it down')
        ->and($detail['task']['description'])->toBe('Somewhere it can be found again')
        ->and($detail['task']['priority'])->toBe(TaskPriority::High->value)
        ->and($detail['task']['dueAt'])->not->toBeNull()
        ->and($detail['task']['assignee']['id'])->toBe($assignee->id)
        ->and($detail['task']['creator']['id'])->toBe($actor->id);
});

it('lists the projects the task appears in, with their columns', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $column = Section::factory()->in($project)->create(['name' => 'Doing']);
    $second = Project::factory()->in($workspace)->create(['name' => 'Release']);
    $task = Task::factory()->in($workspace)->create();

    TaskProjectMembership::factory()->placing($task, $project)->inSection($column)->create();
    TaskProjectMembership::factory()->placing($task, $second)->create();

    $detail = detailOf($task, $actor);
    $names = array_column(array_column($detail['placements'], 'project'), 'name');

    // This is where multi-project membership becomes visible to a person (ADR-0003). Sorted
    // on both sides: the factory names a project randomly, and the order is not the claim.
    $expected = [$project->name, 'Release'];
    sort($names);
    sort($expected);

    expect($names)->toBe($expected)
        ->and($detail['placements'][0]['section']['name'] ?? null)->toBe('Doing');
});

it('hides a project the actor was never given', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $secret = Project::factory()->in($workspace)->create([
        'name' => 'Acquisition',
        'visibility' => ProjectVisibility::Private,
    ]);
    $task = Task::factory()->in($workspace)->create();

    TaskProjectMembership::factory()->placing($task, $project)->create();
    TaskProjectMembership::factory()->placing($task, $secret)->create();

    $names = array_column(array_column(detailOf($task, $actor)['placements'], 'project'), 'name');

    // A task can appear in a project somebody was never given, and its name is not theirs to
    // read through a task they may (ADR-0006).
    expect($names)->toBe([$project->name]);
});

it('says which placements the actor may remove', function (): void {
    [$workspace, $project, $actor] = placeableProject(ProjectAccessLevel::Viewer);
    $editable = Project::factory()->in($workspace)->create();
    ProjectMembership::factory()->in($editable)->forUser($actor)->withAccess(ProjectAccessLevel::Editor)->create();
    $task = Task::factory()->in($workspace)->create();

    TaskProjectMembership::factory()->placing($task, $project)->create();
    TaskProjectMembership::factory()->placing($task, $editable)->create();

    $detail = detailOf($task, $actor);
    $byProject = array_combine(
        array_column(array_column($detail['placements'], 'project'), 'id'),
        array_column($detail['placements'], 'canDetach'),
    );

    expect($byProject[$project->id])->toBeFalse()
        ->and($byProject[$editable->id])->toBeTrue();
});

it('lists the subtasks and names the parent', function (): void {
    [$workspace, , $actor] = placeableProject();
    $parent = Task::factory()->in($workspace)->create(['title' => 'Parent']);
    $task = Task::factory()->in($workspace)->create(['parent_id' => $parent->id]);
    Task::factory()->in($workspace)->create(['parent_id' => $task->id, 'title' => 'First']);
    Task::factory()->in($workspace)->create(['parent_id' => $task->id, 'title' => 'Second']);

    $detail = detailOf($task, $actor);

    expect(array_column($detail['subtasks'], 'title'))->toBe(['First', 'Second'])
        ->and($detail['task']['parent']['title'])->toBe('Parent');
});

it('answers the permissions once, for the task', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    expect(detailOf($task, $actor)['can'])->toBe(['update' => true, 'delete' => true, 'comment' => true]);
});

it('tells a guest what they may not do', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Editor)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    // A guest given the project reads the task and edits nothing; commenting is the one thing
    // their role does carry (ADR-0010).
    expect(detailOf($task, $guest)['can'])
        ->toBe(['update' => false, 'delete' => false, 'comment' => true]);
});

it('reads a task with several subtasks and placements without a query per row', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $second = Project::factory()->in($workspace)->create();
    $task = Task::factory()->in($workspace)->create();

    TaskProjectMembership::factory()->placing($task, $project)->create();
    TaskProjectMembership::factory()->placing($task, $second)->create();

    foreach (range(1, 5) as $index) {
        Task::factory()->in($workspace)->create(['parent_id' => $task->id, 'title' => "Subtask {$index}"]);
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $detail = detailOf($task, $actor);

    /*
     * Five subtasks and two placements: the task, the assignee, the creator, the parent, the
     * children, the placements, their projects and sections, and the memberships the
     * permissions ask for. A bound rather than an exact number, because those membership
     * lookups are memoised per request (TASK-040-020).
     */
    expect($detail['subtasks'])->toHaveCount(5)
        ->and($detail['placements'])->toHaveCount(2)
        ->and(count($queries))->toBeLessThanOrEqual(14);
});

it('carries nothing it cannot yet know about', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    // Comments, activity and attachments are deferred regions whose tables do not exist;
    // followers arrive with TASK-100-010. A query that pretended otherwise would be one
    // somebody has to unpick.
    expect(array_keys(detailOf($task, $actor)))->toBe(['task', 'placements', 'subtasks', 'can']);
});
