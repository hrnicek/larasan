<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * A member who may only read project P, and owns project Q in the same workspace.
 *
 * @return array{Workspace, User, Project, Task, Project}
 */
function readerOfAProjectWithOneOfTheirOwn(ProjectAccessLevel $access, bool $archived = false): array
{
    $workspace = Workspace::factory()->create();
    $reader = memberOf($workspace, WorkspaceRole::Member);

    $restricted = Project::factory()->in($workspace)->private()->create();
    ProjectMembership::factory()->in($restricted)->forUser($reader)->withAccess($access)->create();

    $task = Task::factory()->in($workspace)->create(['title' => 'Restricted']);
    TaskProjectMembership::factory()->placing($task, $restricted)->at(TaskProjectMembership::POSITION_GAP)->create();

    if ($archived) {
        $restricted->forceFill(['archived_at' => now()])->save();
    }

    $own = Project::factory()->in($workspace)->private()->create();
    ProjectMembership::factory()->in($own)->forUser($reader)->withAccess(ProjectAccessLevel::Owner)->create();

    return [$workspace, $reader, $restricted, $task, $own];
}

it('does not let a reader of a project gain edit rights on its task by placing it on a board of their own', function (ProjectAccessLevel $access, bool $archived): void {
    [, $reader, , $task, $own] = readerOfAProjectWithOneOfTheirOwn($access, $archived);

    $this->actingAs($reader)
        ->putJson(route('tasks.update', $task), ['title' => 'Renamed'])
        ->assertForbidden();

    $this->actingAs($reader)
        ->postJson(route('placements.store', $own), ['task' => $task->id])
        ->assertUnprocessable();

    expect($own->placements()->where('task_id', $task->id)->exists())->toBeFalse();

    $this->actingAs($reader)
        ->putJson(route('tasks.update', $task), ['title' => 'Renamed'])
        ->assertForbidden();

    $this->actingAs($reader)
        ->deleteJson(route('tasks.destroy', $task))
        ->assertForbidden();

    expect(Task::query()->whereKey($task->id)->value('title'))->toBe('Restricted');
})->with([
    'viewer' => [ProjectAccessLevel::Viewer, false],
    'commenter' => [ProjectAccessLevel::Commenter, false],
    'editor of an archived project' => [ProjectAccessLevel::Editor, true],
]);

it('still lets an editor of a task place it on another board they can change', function (): void {
    [, $editor, , $task, $own] = readerOfAProjectWithOneOfTheirOwn(ProjectAccessLevel::Editor);

    $this->actingAs($editor)
        ->post(route('placements.store', $own), ['task' => $task->id])
        ->assertRedirect();

    expect($own->placements()->where('task_id', $task->id)->exists())->toBeTrue();
});

it('does not let a reader of a project create a subtask under one of its tasks', function (ProjectAccessLevel $access): void {
    [$workspace, $reader, , $task] = readerOfAProjectWithOneOfTheirOwn($access);

    $this->actingAs($reader)
        ->postJson(route('tasks.store'), ['title' => 'Injected', 'parent_id' => $task->id])
        ->assertUnprocessable();

    expect(Task::query()->where('workspace_id', $workspace->id)->where('title', 'Injected')->exists())->toBeFalse();
})->with([
    'viewer' => [ProjectAccessLevel::Viewer],
    'commenter' => [ProjectAccessLevel::Commenter],
]);

it('does not let a reader of a project move a task of their own under one of its tasks', function (ProjectAccessLevel $access): void {
    [$workspace, $reader, , $task] = readerOfAProjectWithOneOfTheirOwn($access);
    $mine = Task::factory()->in($workspace)->create(['title' => 'Mine', 'created_by' => $reader->id]);

    $this->actingAs($reader)
        ->putJson(route('tasks.update', $mine), ['parent_id' => $task->id])
        ->assertUnprocessable();

    expect(Task::query()->whereKey($mine->id)->value('parent_id'))->toBeNull();
})->with([
    'viewer' => [ProjectAccessLevel::Viewer],
    'commenter' => [ProjectAccessLevel::Commenter],
]);

it('still lets an editor of a project add and move subtasks under its tasks', function (): void {
    [$workspace, $editor, , $task] = readerOfAProjectWithOneOfTheirOwn(ProjectAccessLevel::Editor);
    $mine = Task::factory()->in($workspace)->create(['title' => 'Mine', 'created_by' => $editor->id]);

    $this->actingAs($editor)
        ->post(route('tasks.store'), ['title' => 'A smaller piece', 'parent_id' => $task->id])
        ->assertRedirect();

    $this->actingAs($editor)
        ->put(route('tasks.update', $mine), ['parent_id' => $task->id])
        ->assertRedirect();

    expect(Task::query()->where('parent_id', $task->id)->pluck('title')->sort()->values()->all())
        ->toBe(['A smaller piece', 'Mine']);
});
