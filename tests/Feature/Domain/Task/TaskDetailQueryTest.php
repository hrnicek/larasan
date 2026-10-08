<?php

declare(strict_types=1);

use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Access\MembershipRegistry;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskCollaborator;
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
        array_column($detail['placements'], 'canChange'),
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

    expect(detailOf($task, $actor)['can'])
        ->toBe(['update' => true, 'assign' => true, 'delete' => true, 'comment' => true, 'attach' => true, 'manageTags' => true]);
});

it('tells a guest what they may not do', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Editor)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    expect(detailOf($task, $guest)['can'])
        ->toBe(['update' => false, 'assign' => false, 'delete' => false, 'comment' => true, 'attach' => false, 'manageTags' => false]);
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

    // A bound rather than an exact count, because membership lookups are memoised per request.
    expect($detail['subtasks'])->toHaveCount(5)
        ->and($detail['placements'])->toHaveCount(2)
        ->and(count($queries))->toBeLessThanOrEqual(24);
});

it('carries nothing it cannot yet know about', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    expect(array_keys(detailOf($task, $actor)))
        ->toBe([
            'task',
            'customFields',
            'tags',
            'availableTags',
            'attachments',
            'placements',
            'availableProjects',
            'subtasks',
            'collaborators',
            'collaborating',
            'followers',
            'following',
            'starred',
            'can',
        ]);
});

it('names the people beside the assignee, and says whether the reader is one of them', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $colleague = memberOf($workspace);
    $bystander = memberOf($workspace);
    TaskCollaborator::factory()->on($task, $colleague)->create();
    TaskCollaborator::factory()->on($task, $actor)->create();

    $detail = detailOf($task, $actor);

    expect(array_column($detail['collaborators'], 'id'))->toEqualCanonicalizing([$colleague->id, $actor->id])
        ->and(array_keys($detail['collaborators'][0]))->toBe(['id', 'name', 'email', 'avatar'])
        ->and($detail['collaborating'])->toBeTrue()
        ->and(detailOf($task->fresh() ?? $task, $bystander)['collaborating'])->toBeFalse();
});

it('offers only the projects the actor may add the task to', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $editable = Project::factory()->in($workspace)->create(['name' => 'Editable']);
    ProjectMembership::factory()->in($editable)->forUser($actor)->withAccess(ProjectAccessLevel::Editor)->create();
    $readOnly = Project::factory()->in($workspace)->create(['name' => 'Read only']);
    ProjectMembership::factory()->in($readOnly)->forUser($actor)->withAccess(ProjectAccessLevel::Viewer)->create();
    Project::factory()->in($workspace)->create(['name' => 'Secret', 'visibility' => ProjectVisibility::Private]);

    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $offered = array_column(detailOf($task, $actor)['availableProjects'], 'name');

    expect($offered)->toBe(['Editable']);
});

it('decides which projects to offer without a query per project', function (): void {
    $counts = [];

    foreach ([2, 6] as $projects) {
        [$workspace, $project, $actor] = placeableProject();
        Project::factory()->count($projects)->in($workspace)->create();
        $task = Task::factory()->in($workspace)->create();
        TaskProjectMembership::factory()->placing($task, $project)->create();
        $task = $task->fresh() ?? $task;

        app(MembershipRegistry::class)->flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $offered = app(TaskDetailQuery::class)($task, $actor)['availableProjects'];

        $counts[$projects] = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($offered)->toHaveCount($projects);
    }

    expect($counts[6])->toBe($counts[2]);
});

it('lists what is attached, with the permissions the controls render from', function (): void {
    [$workspace, , $actor] = placeableProject();
    $moderator = memberOf($workspace, WorkspaceRole::Admin);
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $task = Task::factory()->in($workspace)->create();

    $mine = File::factory()->in($workspace)->by($actor)->create(['original_name' => 'mine.pdf', 'size' => 1024]);
    $theirs = File::factory()->in($workspace)->create(['original_name' => 'theirs.pdf']);
    Attachment::factory()->attaching($mine, $task)->create();
    Attachment::factory()->attaching($theirs, $task)->create();

    $attachments = detailOf($task, $actor)['attachments'];

    expect(array_column($attachments, 'name'))->toBe(['mine.pdf', 'theirs.pdf'])
        ->and($attachments[0]['size'])->toBe(1024)
        ->and($attachments[0]['uploader']['id'])->toBe($actor->id)
        ->and($attachments[0])->not->toHaveKey('path');

    expect(array_column($attachments, 'canDelete'))->toBe([true, true])
        ->and(array_column(detailOf($task, $moderator)['attachments'], 'canDelete'))->toBe([true, true])
        ->and(array_column(detailOf($task, $guest)['attachments'], 'canDelete'))->toBe([false, false]);
});

it("reads a task's attachments without a query per file", function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    foreach (range(1, 6) as $index) {
        $file = File::factory()->in($workspace)->create(['original_name' => "file-{$index}.pdf"]);
        Attachment::factory()->attaching($file, $task)->create();
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $detail = detailOf($task, $actor);

    expect($detail['attachments'])->toHaveCount(6)
        ->and(count($queries))->toBeLessThanOrEqual(22);
});

it('offers each placement the columns of its own project, in order', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $other = Project::factory()->in($workspace)->create();
    ProjectMembership::factory()->in($other)->forUser($actor)->withAccess(ProjectAccessLevel::Editor)->create();

    $doing = Section::factory()->in($project)->create(['name' => 'Doing', 'position' => 2000]);
    Section::factory()->in($project)->create(['name' => 'To do', 'position' => 1000]);
    $elsewhere = Section::factory()->in($other)->create(['name' => 'Elsewhere']);

    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->inSection($doing)->create();
    TaskProjectMembership::factory()->placing($task, $other)->create();

    $placements = detailOf($task, $actor)['placements'];
    $byProject = array_combine(array_column(array_column($placements, 'project'), 'id'), $placements);

    expect(array_column($byProject[$project->id]['sections'], 'name'))->toBe(['To do', 'Doing'])
        ->and(array_column($byProject[$other->id]['sections'], 'name'))->toBe([$elsewhere->name])
        ->and(array_column($byProject[$project->id]['sections'], 'id'))->not->toContain($elsewhere->id);
});
