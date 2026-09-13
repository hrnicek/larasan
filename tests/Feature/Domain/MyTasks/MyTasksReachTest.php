<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\MyTasksTab;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Inertia\Testing\AssertableInertia;

/**
 * @return array<string, mixed>
 */
function dueFor(MyTasksTab $tab): array
{
    return match ($tab) {
        MyTasksTab::Today => ['due_at' => now()],
        MyTasksTab::Overdue => ['due_at' => now()->subWeek()],
        MyTasksTab::Upcoming => ['due_at' => now()->addWeek()],
        MyTasksTab::Completed => ['due_at' => now(), 'completed_at' => now()],
        MyTasksTab::Starred => ['due_at' => now()],
    };
}

it('leaves out an assigned task the reader can no longer reach on every assignment tab', function (MyTasksTab $tab): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $private = Project::factory()->in($workspace)->private()->create();

    $task = Task::factory()->in($workspace)->create(['title' => 'Negotiate severance', 'assignee_id' => $actor->id, ...dueFor($tab)]);
    TaskProjectMembership::factory()->placing($task, $private)->create();
    Task::factory()->in($workspace)->create(['title' => 'Negotiate the terms', 'assignee_id' => $actor->id, 'parent_id' => $task->id, ...dueFor($tab)]);
    Task::factory()->in($workspace)->create(['title' => 'Still mine', 'assignee_id' => $actor->id, ...dueFor($tab)]);

    expect(array_column(myTasks($workspace, $actor, $tab)['tasks'], 'title'))->toBe(['Still mine']);

    $this->actingAs($actor)
        ->get(route('my-tasks.index', ['tab' => $tab->value]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Still mine'));
})->with([
    'today' => MyTasksTab::Today,
    'overdue' => MyTasksTab::Overdue,
    'upcoming' => MyTasksTab::Upcoming,
    'completed' => MyTasksTab::Completed,
]);

it('keeps an assigned subtask of a project the reader belongs to', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $private = Project::factory()->in($workspace)->private()->create();
    ProjectMembership::factory()->in($private)->forUser($actor)->withAccess(ProjectAccessLevel::Editor)->create();

    $parent = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($parent, $private)->create();
    Task::factory()->in($workspace)->create(['title' => 'Mine', 'assignee_id' => $actor->id, 'parent_id' => $parent->id, 'due_at' => now()]);

    expect(array_column(myTasks($workspace, $actor)['tasks'], 'title'))->toBe(['Mine']);
});

it('leaves a guest s assigned task out once it is on no project they were given', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    Task::factory()->in($workspace)->create(['title' => 'Filed nowhere', 'assignee_id' => $guest->id, 'due_at' => now()]);

    expect(myTasks($workspace, $guest)['tasks'])->toBe([]);
});

it('lets an unplaced subtask be ticked off only where its parent s project allows changes', function (ProjectAccessLevel $access, bool $canUpdate): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();
    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();

    $parent = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($parent, $project)->create();
    Task::factory()->in($workspace)->create(['assignee_id' => $actor->id, 'parent_id' => $parent->id, 'due_at' => now()]);

    expect(myTasks($workspace, $actor)['tasks'][0]['canUpdate'])->toBe($canUpdate);
})->with([
    'editor' => [ProjectAccessLevel::Editor, true],
    'viewer' => [ProjectAccessLevel::Viewer, false],
]);

it('shows a guest the assignee without an address', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $member = memberOf($workspace);
    $given = Project::factory()->in($workspace)->private()->create();
    ProjectMembership::factory()->in($given)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();

    $task = Task::factory()->in($workspace)->create(['assignee_id' => $guest->id, 'due_at' => now()]);
    TaskProjectMembership::factory()->placing($task, $given)->create();

    expect(myTasks($workspace, $guest)['tasks'][0]['assignee'])->not->toHaveKey('email')
        ->and(myTasks($workspace, $guest)['tasks'][0]['assignee']['id'])->toBe($guest->id);

    Task::factory()->in($workspace)->create(['assignee_id' => $member->id, 'due_at' => now()]);

    expect(myTasks($workspace, $member)['tasks'][0]['assignee'])->toHaveKey('email', $member->email);
});
