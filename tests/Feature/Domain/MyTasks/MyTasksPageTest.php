<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Queries\CurrentWorkspace;
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

    $this->actingAs($actor->fresh())
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
