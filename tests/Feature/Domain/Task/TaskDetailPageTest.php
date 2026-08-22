<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Ancestry\ParentChain;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Inertia\Testing\AssertableInertia;

it('requires authentication', function (): void {
    [$workspace] = placeableProject();

    $this->get(route('tasks.show', Task::factory()->in($workspace)->create()))
        ->assertRedirect(route('login'));
});

it('renders a task at its own url', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create(['title' => 'Write it down']);
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('tasks/Show')
            ->where('task.title', 'Write it down')
            ->where('can.update', true)
            ->has('placements', 1));
});

it('renders a task that is in no project at all', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();

    // A task with no placements is still a task (ADR-0003), and its page says so rather than
    // refusing to render.
    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('placements', 0));
});

it('hides a task in another workspace behind a 404', function (): void {
    [, , $actor] = placeableProject();
    [$otherWorkspace] = placeableProject();

    $this->actingAs($actor)
        ->get(route('tasks.show', Task::factory()->in($otherWorkspace)->create()))
        ->assertNotFound();
});

it('refuses a task that lives only in a project the actor was not given', function (): void {
    [$workspace, , $actor] = placeableProject();
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $private)->create();

    // They can see the workspace, so the task is not a secret's existence — the access is
    // what is refused (TASK-070-017).
    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertForbidden();
});

it('lets a guest read a task in a project they were given', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    $this->actingAs($guest)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('can.update', false)
            ->where('can.comment', true));
});

it('sends the lists the detail s own controls need', function (): void {
    [$workspace, , $actor] = placeableProject();
    $member = memberOf($workspace, WorkspaceRole::Member);
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($member): void {
            $props = $page->toArray()['props'];

            // The same two lists the project screen sends, because the panel's controls are
            // the same components the list row uses.
            expect(array_column($props['members'], 'id'))->toContain($member->id)
                ->and($props['priorities'])->toContain('urgent');
        });
});

it('renames a task from its own page', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create(['title' => 'Old name', 'description' => 'Kept']);

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('tasks.update', $task), ['title' => 'New name'])
        ->assertRedirect(route('tasks.show', $task));

    // Only the title was sent, so the description is untouched (TASK-080-008).
    expect($task->fresh()?->title)->toBe('New name')
        ->and($task->fresh()?->description)->toBe('Kept');
});

it('clears a description with an explicit null and refuses a blank title', function (): void {
    [$workspace, , $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create(['description' => 'Going']);

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('tasks.update', $task), ['description' => null])
        ->assertRedirect();

    expect($task->fresh()?->description)->toBeNull();

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('tasks.update', $task), ['title' => '   '])
        ->assertSessionHasErrors('title');
});

it('creates a subtask from the detail', function (): void {
    [$workspace, , $actor] = placeableProject();
    $parent = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->from(route('tasks.show', $parent))
        ->post(route('tasks.store'), ['title' => 'A smaller piece', 'parent_id' => $parent->id])
        ->assertRedirect(route('tasks.show', $parent));

    expect($parent->children()->pluck('title')->all())->toBe(['A smaller piece']);
});

it('surfaces the depth limit rather than pre-empting it', function (): void {
    [$workspace, , $actor] = placeableProject();

    $deepest = Task::factory()->in($workspace)->create();

    foreach (range(1, ParentChain::MAX_DEPTH - 1) as $ignored) {
        $deepest = Task::factory()->in($workspace)->create(['parent_id' => $deepest->id]);
    }

    /*
     * The limit belongs to the Action (`ParentChain::MAX_DEPTH`); the client surfaces its
     * refusal rather than counting depth itself, because a second copy of the rule is the one
     * that drifts. `TaskController` translates the refusal onto the field it is about, which
     * is a better answer than the generic `refusal` key the renderer would give it.
     */
    $this->actingAs($actor)
        ->from(route('tasks.show', $deepest))
        ->post(route('tasks.store'), ['title' => 'One too deep', 'parent_id' => $deepest->id])
        ->assertSessionHasErrors(['parent_id' => 'Subtasks cannot be nested that deeply.']);

    expect($deepest->children()->count())->toBe(0);
});
