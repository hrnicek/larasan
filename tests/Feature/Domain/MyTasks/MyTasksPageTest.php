<?php

declare(strict_types=1);

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

    // The view lives in the address, so a link carries it and a refresh lands back on it.
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

    // A tab somebody typed is a request for a screen, not an error worth a 404.
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

    /*
     * `CurrentWorkspace` memoises its answer for the length of a request. A test makes two
     * requests through one container, so the memo has to be emptied here the way a new request
     * would empty it — under PHP-FPM every request boots its own. Worth knowing if this
     * application ever runs on a persistent worker.
     */
    app(CurrentWorkspace::class)->flush();

    $this->actingAs($actor->refresh())
        ->get(route('my-tasks.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('tasks.0.title', 'There'));
});

it('tells the screen whether the reader may tick anything off', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace, WorkspaceRole::Member);
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    $this->actingAs($member)
        ->get(route('my-tasks.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('can.updateTask', true));

    // A guest can be given work and cannot change it: the checkbox is inert rather than absent,
    // so the row still reads the same (TASK-080-005).
    $this->actingAs($guest)
        ->get(route('my-tasks.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('can.updateTask', false));
});

it('answers a tab switch with the list region alone', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Late', 'assignee_id' => $actor->id, 'due_at' => now()->subWeek()]);

    /*
     * The partial reload the tabs make. The shell and the tab list are already correct, so the
     * server is asked for the two props that changed rather than for the page again.
     */
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
        // The props it did not ask for are absent, which is the point of asking.
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

    // Twenty-five then five, each row once: "load more" adds to a list rather than shuffling it.
    expect($titles)->toHaveCount(30)
        ->and(array_unique($titles))->toHaveCount(30);
});

it('refuses to be paged past the end into nothing sensible', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['assignee_id' => $actor->id, 'due_at' => now()]);

    // A page nobody has is an empty page, not an error: a stale link should still render.
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

    // The row is the list view's component and it draws chips unconditionally: a payload without
    // the key crashes the render, and a crashed render is a screen whose controls do nothing.
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
