<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * A task's parent and its subtasks are tasks, and a task is reachable or it is not (ADR-0003,
 * ADR-0006). The detail payload filtered its placements, its custom fields and its attachments
 * by reach and sent the ancestry unfiltered, so a private project's work was named through a
 * task somebody was allowed to read.
 */

/**
 * A workspace, a member of it, and a private project that member was never given.
 *
 * @return array{Workspace, User, Project}
 */
function ancestryFixture(): array
{
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $closed = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);

    return [$workspace, $actor, $closed];
}

/** A task on a board the whole workspace can open. */
function reachableTask(Workspace $workspace, string $title = 'Readable'): Task
{
    $open = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    $task = Task::factory()->in($workspace)->create(['title' => $title]);
    TaskProjectMembership::factory()->placing($task, $open)->create();

    return $task;
}

it('does not name a subtask that lives only in a project the reader cannot open', function (): void {
    [$workspace, $actor, $closed] = ancestryFixture();
    $task = reachableTask($workspace);

    $hidden = Task::factory()->in($workspace)->create(['title' => 'Secret subtask', 'parent_id' => $task->id]);
    TaskProjectMembership::factory()->placing($hidden, $closed)->create();

    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('subtasks', 0));
});

it('still names a subtask the reader can reach', function (): void {
    [$workspace, $actor] = ancestryFixture();
    $task = reachableTask($workspace);

    // Filed nowhere, which is workspace work and readable by any member — the case the filter
    // must not swallow along with the private one.
    Task::factory()->in($workspace)->create(['title' => 'Ordinary subtask', 'parent_id' => $task->id]);

    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('subtasks', 1)
            ->where('subtasks.0.title', 'Ordinary subtask'));
});

it('does not name a parent that lives only in a project the reader cannot open', function (): void {
    [$workspace, $actor, $closed] = ancestryFixture();

    $parent = Task::factory()->in($workspace)->create(['title' => 'Secret parent']);
    TaskProjectMembership::factory()->placing($parent, $closed)->create();

    $task = reachableTask($workspace);
    $task->forceFill(['parent_id' => $parent->id])->save();

    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('task.parent', null));
});

it('refuses a parent the actor cannot reach', function (): void {
    [$workspace, $actor, $closed] = ancestryFixture();

    $parent = Task::factory()->in($workspace)->create(['title' => 'Secret parent']);
    TaskProjectMembership::factory()->placing($parent, $closed)->create();

    $task = reachableTask($workspace);

    /*
     * The active half of the same leak: `parent_id` was scoped to the workspace and nothing
     * more, so naming an unreachable task as your own task's parent read its title back to you
     * through the panel.
     */
    $this->actingAs($actor)
        ->put(route('tasks.update', $task), ['parent_id' => $parent->id])
        ->assertSessionHasErrors('parent_id');

    expect($task->fresh()?->parent_id)->toBeNull();
});

it('refuses to create a task under a parent the actor cannot reach', function (): void {
    [$workspace, $actor, $closed] = ancestryFixture();

    $parent = Task::factory()->in($workspace)->create(['title' => 'Secret parent']);
    TaskProjectMembership::factory()->placing($parent, $closed)->create();

    $this->actingAs($actor)
        ->withSession(['workspace_id' => $workspace->id])
        ->post(route('tasks.store'), ['title' => 'Mine', 'parent_id' => $parent->id])
        ->assertSessionHasErrors('parent_id');

    expect(Task::query()->where('title', 'Mine')->exists())->toBeFalse();
});
