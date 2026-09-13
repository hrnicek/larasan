<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Queries\CurrentWorkspace;
use App\Http\Middleware\HandleInertiaRequests;
use Inertia\Testing\AssertableInertia;

it('requires authentication', function (): void {
    $this->get(route('my-tasks.index'))->assertRedirect(route('login'));
});

it('renders what is due today by default', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Today', 'assignee_id' => $actor->id, 'due_at' => now()]);
    Task::factory()->in($workspace)->create(['title' => 'Next week', 'assignee_id' => $actor->id, 'due_at' => now()->addWeek()]);

    $this->actingAs($actor)
        ->get(route('my-tasks.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('my-tasks/Index')
            ->where('meta.tab', 'today')
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Today'));
});

it('reads the tab and the page from the URL', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Late', 'assignee_id' => $actor->id, 'due_at' => now()->subWeek()]);

    $this->actingAs($actor)
        ->get(route('my-tasks.index', ['tab' => 'overdue']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('meta.tab', 'overdue')
            ->where('tasks.0.title', 'Late'));
});

it('falls back to today when the tab is not one of them', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    $this->actingAs($actor)
        ->get(route('my-tasks.index', ['tab' => 'whenever']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('meta.tab', 'today'));
});

it('shows the workspace the actor is standing in, and not the other one', function (): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace);
    memberOf($elsewhere, user: $actor);

    Task::factory()->in($workspace)->create(['title' => 'Here', 'assignee_id' => $actor->id, 'due_at' => now()]);
    Task::factory()->in($elsewhere)->create(['title' => 'There', 'assignee_id' => $actor->id, 'due_at' => now()]);

    $this->actingAs($actor)
        ->get(route('my-tasks.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Here'));

    $actor->forceFill(['current_workspace_id' => $elsewhere->id])->save();

    // CurrentWorkspace memoises per request, and both requests here share one container.
    app(CurrentWorkspace::class)->flush();

    $this->actingAs($actor->refresh())
        ->get(route('my-tasks.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('tasks.0.title', 'There'));
});

it('tells each row whether the reader may tick it off', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $given = Project::factory()->in($workspace)->private()->create();
    ProjectMembership::factory()->in($given)->forUser($guest)->withAccess(ProjectAccessLevel::Editor)->create();

    Task::factory()->in($workspace)->create(['assignee_id' => $member->id, 'due_at' => now()]);
    $guestsTask = Task::factory()->in($workspace)->create(['assignee_id' => $guest->id, 'due_at' => now()]);
    TaskProjectMembership::factory()->placing($guestsTask, $given)->create();

    $this->actingAs($member)
        ->get(route('my-tasks.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('tasks.0.canUpdate', true));

    $this->actingAs($guest)
        ->get(route('my-tasks.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('tasks.0.canUpdate', false));
});

it('answers per row rather than per screen when the boards disagree', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    $open = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    $restricted = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);
    ProjectMembership::factory()->in($restricted)->forUser($actor)->withAccess(ProjectAccessLevel::Viewer)->create();

    $editable = Task::factory()->in($workspace)->create(['title' => 'On the open board', 'assignee_id' => $actor->id, 'due_at' => now()]);
    $readOnly = Task::factory()->in($workspace)->create(['title' => 'On the restricted board', 'assignee_id' => $actor->id, 'due_at' => now()]);
    TaskProjectMembership::factory()->placing($editable, $open)->create();
    TaskProjectMembership::factory()->placing($readOnly, $restricted)->create();

    $this->actingAs($actor)
        ->get(route('my-tasks.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 2)
            ->where('tasks.0.canUpdate', true)
            ->where('tasks.0.title', 'On the open board')
            ->where('tasks.1.canUpdate', false)
            ->where('tasks.1.title', 'On the restricted board'));
});

it('answers a tab switch with the list region alone', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Late', 'assignee_id' => $actor->id, 'due_at' => now()->subWeek()]);

    $this->actingAs($actor)
        ->withoutMiddleware(HandleInertiaRequests::class)
        ->get(route('my-tasks.index', ['tab' => 'overdue']), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'my-tasks/Index',
            'X-Inertia-Partial-Data' => 'tasks,meta',
        ])
        ->assertOk()
        ->assertJsonPath('props.meta.tab', 'overdue')
        ->assertJsonPath('props.tasks.0.title', 'Late')
        ->assertJsonMissingPath('props.can');
});

it('pages without repeating a row', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    foreach (range(1, 30) as $index) {
        Task::factory()->in($workspace)->create([
            'title' => "Task {$index}",
            'assignee_id' => $actor->id,
            'due_at' => now()->startOfHour()->addMinutes($index),
        ]);
    }

    $first = $this->actingAs($actor)->get(route('my-tasks.index'));
    $second = $this->actingAs($actor)->get(route('my-tasks.index', ['page' => 2]));

    $titles = array_merge(
        array_column($first->viewData('page')['props']['tasks'], 'title'),
        array_column($second->viewData('page')['props']['tasks'], 'title'),
    );

    expect($titles)->toHaveCount(30)
        ->and(array_unique($titles))->toHaveCount(30);
});

it('refuses to be paged past the end into nothing sensible', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['assignee_id' => $actor->id, 'due_at' => now()]);

    $this->actingAs($actor)
        ->get(route('my-tasks.index', ['page' => 9]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 0)
            ->where('meta.hasMore', false));
});

it('carries the tags a row draws', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $task = Task::factory()->in($workspace)->create(['assignee_id' => $actor->id, 'due_at' => now()]);
    $task->tags()->attach(Tag::factory()->in($workspace)->named('Billing')->create());

    // The row component renders tags unconditionally, so the key must always be present.
    $this->actingAs($actor)
        ->get(route('my-tasks.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks.0.tags', 1)
            ->where('tasks.0.tags.0.name', 'Billing'));
});

it('sends an empty list for a task nobody tagged', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['assignee_id' => $actor->id, 'due_at' => now()]);

    $this->actingAs($actor)
        ->get(route('my-tasks.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('tasks.0.tags', 0));
});
