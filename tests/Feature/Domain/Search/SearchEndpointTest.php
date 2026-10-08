<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Inertia\Testing\AssertableInertia;

it('requires authentication', function (): void {
    $this->get(route('search.index'))->assertRedirect(route('login'));
});

it('finds tasks from the query string', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Fix the login screen']);
    Task::factory()->in($workspace)->create(['title' => 'Write the changelog']);

    $this->actingAs($actor)
        ->get(route('search.index', ['q' => 'login']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('search/Index')
            ->where('meta.term', 'login')
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Fix the login screen'));
});

it('returns nothing at all without a term', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Task::factory()->in($workspace)->create(['title' => 'Something']);

    $this->actingAs($actor)
        ->get(route('search.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 0)
            ->where('meta.term', ''));
});

it('narrows by project, assignee and completion from the URL', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $assignee = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();

    $one = Task::factory()->in($workspace)->create(['title' => 'Login one', 'assignee_id' => $assignee->id]);
    TaskProjectMembership::factory()->placing($one, $project)->create();
    Task::factory()->in($workspace)->create(['title' => 'Login two', 'completed_at' => now()]);

    $this->actingAs($actor)
        ->get(route('search.index', ['q' => 'login', 'project' => $project->id]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Login one'));

    $this->actingAs($actor)
        ->get(route('search.index', ['q' => 'login', 'assignee' => $assignee->id]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('tasks', 1));

    // completed=0 must not be treated as an absent filter.
    $this->actingAs($actor)
        ->get(route('search.index', ['q' => 'login', 'completed' => '0']))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Login one')
            ->where('filters.completed', false));
});

it('offers only the projects this actor can open as filters', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    Project::factory()->in($workspace)->create(['name' => 'Open']);
    Project::factory()->in($workspace)->create(['name' => 'Private', 'visibility' => ProjectVisibility::Private]);

    $this->actingAs($actor)
        ->get(route('search.index', ['q' => 'anything']))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('filterProjects', 1)
            ->where('projects.0.name', 'Open'));
});

it('refuses a term longer than anybody typed', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    $this->actingAs($actor)
        ->get(route('search.index', ['q' => str_repeat('a', 201)]))
        ->assertSessionHasErrors('q');
});

it('pages from the URL', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    foreach (range(1, 30) as $index) {
        Task::factory()->in($workspace)->create(['title' => "Login task {$index}"]);
    }

    $this->actingAs($actor)
        ->get(route('search.index', ['q' => 'login', 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 5)
            ->where('meta.page', 2)
            ->where('meta.hasMore', false));
});

it('shows the workspace the actor is standing in', function (): void {
    $workspace = Workspace::factory()->create();
    $elsewhere = Workspace::factory()->create();
    $actor = memberOf($workspace);
    memberOf($elsewhere, user: $actor);

    Task::factory()->in($workspace)->create(['title' => 'Ours: login']);
    Task::factory()->in($elsewhere)->create(['title' => 'Theirs: login']);

    $this->actingAs($actor)
        ->get(route('search.index', ['q' => 'login']))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('tasks', 1)
            ->where('tasks.0.title', 'Ours: login'));
});
