<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Actions\StarTask;
use App\Domain\Task\Actions\UnstarTask;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Models\TaskStar;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia;

it('stars a task from wherever the panel was opened', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $task = Task::factory()->in($project->workspace)->create();

    $this->actingAs($actor)
        ->from(route('projects.show', $project))
        ->post(route('tasks.star', $task))
        ->assertRedirect(route('projects.show', $project));

    expect(TaskStar::query()->where('task_id', $task->id)->where('user_id', $actor->id)->exists())->toBeTrue();
});

it('treats starring twice as starring once', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $task = Task::factory()->in($project->workspace)->create();

    $this->actingAs($actor)->post(route('tasks.star', $task));
    $this->actingAs($actor)->post(route('tasks.star', $task));

    expect(TaskStar::query()->where('task_id', $task->id)->count())->toBe(1);
});

it('unstars, and says nothing when there was no star', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $task = Task::factory()->in($project->workspace)->create();
    TaskStar::factory()->starring($task, $actor)->create();

    $this->actingAs($actor)->delete(route('tasks.unstar', $task));

    expect(TaskStar::query()->where('task_id', $task->id)->exists())->toBeFalse();

    $this->actingAs($actor)->delete(route('tasks.unstar', $task))->assertRedirect();

    expect(TaskStar::query()->where('task_id', $task->id)->exists())->toBeFalse();
});

it('lets a reader star a task they may only look at', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);
    $task = Task::factory()->in($project->workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $this->actingAs($actor)->post(route('tasks.star', $task))->assertRedirect();

    expect(TaskStar::query()->where('task_id', $task->id)->exists())->toBeTrue();
});

it('refuses a task in another workspace', function (): void {
    [, $actor] = projectFor(WorkspaceRole::Admin, ProjectAccessLevel::Owner);
    $elsewhere = Task::factory()->in(Workspace::factory()->create())->create();

    $this->actingAs($actor)->post(route('tasks.star', $elsewhere))->assertNotFound();

    expect(TaskStar::query()->where('task_id', $elsewhere->id)->exists())->toBeFalse();
});

it('refuses every caller who cannot reach the task, not only the HTTP one', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Admin);

    expect(fn (): TaskStar => app(StarTask::class)->handle($task, $outsider))
        ->toThrow(TaskException::class);
});

it('lets somebody unstar a task they can no longer open', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Guest);
    $task = Task::factory()->in($workspace)->create();
    TaskStar::factory()->starring($task, $actor)->create();

    expect($actor->can('view', $task))->toBeFalse();

    app(UnstarTask::class)->handle($task, $actor);

    expect(TaskStar::query()->where('task_id', $task->id)->exists())->toBeFalse();
});

it('tells the panel whether this reader starred the task', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $task = Task::factory()->in($project->workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $this->actingAs($actor)
        ->get(route('projects.show', ['project' => $project, 'task' => $task->id]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('taskDetail.starred', false));

    TaskStar::factory()->starring($task, $actor)->create();

    $this->actingAs($actor)
        ->get(route('projects.show', ['project' => $project, 'task' => $task->id]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('taskDetail.starred', true));
});

it('lists what this person starred, including work somebody else was given', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $somebodyElse = memberOf($project->workspace, WorkspaceRole::Member);
    $theirs = Task::factory()->in($project->workspace)->create(['assignee_id' => $somebodyElse->id]);
    TaskProjectMembership::factory()->placing($theirs, $project)->create();
    Task::factory()->in($project->workspace)->create(['assignee_id' => $actor->id]);
    TaskStar::factory()->starring($theirs, $actor)->create();

    $this->actingAs($actor)
        ->get(route('my-tasks.index', ['tab' => 'starred']))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 1)
            ->where('tasks.0.id', $theirs->id));
});

it('keeps a task the reader cannot open out of their starred tab', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->private()->create();
    $unreachable = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($unreachable, $private)->create();
    TaskStar::factory()->starring($unreachable, $actor)->create();

    $this->actingAs($actor)
        ->get(route('my-tasks.index', ['tab' => 'starred']))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('tasks', 0));
});

it('sinks finished work to the bottom of the starred tab rather than hiding it', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $done = Task::factory()->in($workspace)->create(['completed_at' => now()->subDay()]);
    $open = Task::factory()->in($workspace)->create();
    TaskStar::factory()->starring($done, $actor)->create();
    TaskStar::factory()->starring($open, $actor)->create();

    $this->actingAs($actor)
        ->get(route('my-tasks.index', ['tab' => 'starred']))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 2)
            ->where('tasks.0.id', $open->id)
            ->where('tasks.1.id', $done->id));
});

it('leaves the dated tabs about what somebody was given', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $somebodyElses = Task::factory()->in($workspace)->create(['due_at' => now()->addHours(2)]);
    TaskStar::factory()->starring($somebodyElses, $actor)->create();

    $this->actingAs($actor)
        ->get(route('my-tasks.index', ['tab' => 'today']))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('tasks', 0));
});

it('refuses a second star in the database itself', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create();
    TaskStar::factory()->starring($task, $actor)->create();

    expect(fn (): TaskStar => TaskStar::factory()->starring($task, $actor)->create())
        ->toThrow(QueryException::class);
});

it('takes the star with the task', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create();
    TaskStar::factory()->starring($task, $actor)->create();

    $task->forceDelete();

    expect(TaskStar::query()->where('task_id', $task->id)->exists())->toBeFalse();
});
