<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\ReachableTasks;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{Workspace, Project, User, User}
 */
function privateProjectWithInsider(ProjectAccessLevel $access = ProjectAccessLevel::Editor): array
{
    $workspace = Workspace::factory()->create();
    $insider = memberOf($workspace, WorkspaceRole::Member);
    $outsider = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->private()->create();

    ProjectMembership::factory()->in($private)->forUser($insider)->withAccess($access)->create();

    return [$workspace, $private, $insider, $outsider];
}

function placedTaskIn(Workspace $workspace, Project $project, string $title): Task
{
    $task = Task::factory()->in($workspace)->create(['title' => $title]);
    TaskProjectMembership::factory()
        ->placing($task, $project)
        ->at(($project->placements()->count() + 1) * TaskProjectMembership::POSITION_GAP)
        ->create();

    return $task;
}

function unplacedSubtaskOf(Workspace $workspace, Task $parent, string $title): Task
{
    return Task::factory()->in($workspace)->create(['title' => $title, 'parent_id' => $parent->id]);
}

/**
 * @return list<string>
 */
function reachableTitles(Workspace $workspace, User $actor): array
{
    return array_values(app(ReachableTasks::class)
        ->constrain(Task::query(), $workspace, $actor)
        ->orderBy('title')
        ->get(['tasks.title'])
        ->map(fn (Task $task): string => $task->title)
        ->all());
}

it('keeps a member outside a private project out of a subtask of one of its tasks', function (string $ability): void {
    [$workspace, $private, , $outsider] = privateProjectWithInsider();
    $parent = placedTaskIn($workspace, $private, 'Restructure the team');
    $subtask = unplacedSubtaskOf($workspace, $parent, 'Negotiate severance');

    expect(Gate::forUser($outsider)->allows($ability, $subtask))->toBeFalse();
})->with(['view', 'update', 'complete', 'assign', 'delete', 'comment', 'attach']);

it('refuses the subtask over HTTP and leaves it out of search for a member outside the project', function (): void {
    [$workspace, $private, , $outsider] = privateProjectWithInsider();
    $parent = placedTaskIn($workspace, $private, 'Restructure the team');
    $subtask = unplacedSubtaskOf($workspace, $parent, 'Negotiate severance');

    $this->actingAs($outsider)->get(route('tasks.show', $subtask))->assertForbidden();

    $this->actingAs($outsider)
        ->put(route('tasks.update', $subtask), ['title' => 'Renamed'])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->get(route('search.index', ['q' => 'severance']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('tasks', 0));

    expect($subtask->fresh()?->title)->toBe('Negotiate severance')
        ->and(reachableTitles($workspace, $outsider))->toBe([]);
});

it('lets a member of the private project reach the subtask', function (): void {
    [$workspace, $private, $insider] = privateProjectWithInsider();
    $parent = placedTaskIn($workspace, $private, 'Restructure the team');
    $subtask = unplacedSubtaskOf($workspace, $parent, 'Negotiate severance');

    expect(Gate::forUser($insider)->allows('view', $subtask))->toBeTrue()
        ->and(Gate::forUser($insider)->allows('update', $subtask))->toBeTrue()
        ->and(reachableTitles($workspace, $insider))->toBe(['Negotiate severance', 'Restructure the team']);

    $this->actingAs($insider)->get(route('tasks.show', $subtask))->assertOk();

    $this->actingAs($insider)
        ->put(route('tasks.update', $subtask), ['title' => 'Negotiate the package'])
        ->assertRedirect();

    $this->actingAs($insider)
        ->get(route('search.index', ['q' => 'package']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Negotiate the package'));
});

it('lets a guest given the private project read its subtasks', function (): void {
    [$workspace, $private] = privateProjectWithInsider();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    ProjectMembership::factory()->in($private)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();

    $subtask = unplacedSubtaskOf($workspace, placedTaskIn($workspace, $private, 'Parent'), 'Child');

    expect(Gate::forUser($guest)->allows('view', $subtask))->toBeTrue()
        ->and(Gate::forUser($guest)->allows('update', $subtask))->toBeFalse()
        ->and(reachableTitles($workspace, $guest))->toBe(['Child', 'Parent']);
});

it('holds a viewer of the private project to reading its subtasks', function (): void {
    [$workspace, $private, $viewer] = privateProjectWithInsider(ProjectAccessLevel::Viewer);
    $subtask = unplacedSubtaskOf($workspace, placedTaskIn($workspace, $private, 'Parent'), 'Child');

    expect(Gate::forUser($viewer)->allows('view', $subtask))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('update', $subtask))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('comment', $subtask))->toBeFalse();
});

it('follows a chain of unplaced subtasks up to the placed ancestor', function (): void {
    [$workspace, $private, $insider, $outsider] = privateProjectWithInsider();

    $task = placedTaskIn($workspace, $private, 'Level 0');

    foreach (range(1, 8) as $level) {
        $task = unplacedSubtaskOf($workspace, $task, "Level {$level}");
    }

    expect(Gate::forUser($outsider)->allows('view', $task))->toBeFalse()
        ->and(Gate::forUser($insider)->allows('view', $task))->toBeTrue()
        ->and(Gate::forUser($insider)->allows('update', $task))->toBeTrue()
        ->and(reachableTitles($workspace, $outsider))->toBe([])
        ->and(reachableTitles($workspace, $insider))->toHaveCount(9);
});

it('lets the nearest placed ancestor decide', function (): void {
    [$workspace, $private, , $outsider] = privateProjectWithInsider();
    $open = Project::factory()->in($workspace)->create();

    $underOpen = placedTaskIn($workspace, $open, 'Open middle');
    $underOpen->forceFill(['parent_id' => placedTaskIn($workspace, $private, 'Private root')->id])->save();
    $readable = unplacedSubtaskOf($workspace, $underOpen, 'Readable leaf');

    $underPrivate = placedTaskIn($workspace, $private, 'Private middle');
    $underPrivate->forceFill(['parent_id' => placedTaskIn($workspace, $open, 'Open root')->id])->save();
    $hidden = unplacedSubtaskOf($workspace, $underPrivate, 'Hidden leaf');

    expect(Gate::forUser($outsider)->allows('view', $readable))->toBeTrue()
        ->and(Gate::forUser($outsider)->allows('update', $readable))->toBeTrue()
        ->and(Gate::forUser($outsider)->allows('view', $hidden))->toBeFalse()
        ->and(reachableTitles($workspace, $outsider))->toBe(['Open middle', 'Open root', 'Readable leaf']);
});

it('leaves a task on no board and under no parent to the whole workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $root = Task::factory()->in($workspace)->create(['title' => 'Root']);
    $child = unplacedSubtaskOf($workspace, $root, 'Child');

    expect(Gate::forUser($member)->allows('view', $root))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $root))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $child))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $child))->toBeTrue()
        ->and(Gate::forUser($guest)->allows('view', $root))->toBeFalse()
        ->and(Gate::forUser($guest)->allows('view', $child))->toBeFalse()
        ->and(reachableTitles($workspace, $member))->toBe(['Child', 'Root'])
        ->and(reachableTitles($workspace, $guest))->toBe([]);
});

it("treats unplaced tasks caught in a parent loop as nobody's workspace work", function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);
    $first = Task::factory()->in($workspace)->create(['title' => 'First']);
    $second = unplacedSubtaskOf($workspace, $first, 'Second');
    $first->forceFill(['parent_id' => $second->id])->save();

    expect(Gate::forUser($member)->allows('view', $first))->toBeFalse()
        ->and(reachableTitles($workspace, $member))->toBe([]);
});
