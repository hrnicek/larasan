<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\ProjectBoardQuery;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

it('requires authentication', function (): void {
    [, $project] = placeableProject();

    $this->get(route('projects.show', $project))->assertRedirect(route('login'));
});

it('renders the project in the view the project prefers', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $project->forceFill(['default_view' => ProjectDefaultView::Board])->save();
    attach(Task::factory()->in($workspace)->create(['title' => 'Write it down']), $project, $actor);

    $this->actingAs($actor)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('projects/Show')
            ->where('view', ProjectDefaultView::Board->value)
            ->where('project.name', $project->name)
            ->where('board.columns.0.tasks.0.title', 'Write it down')
            // Only the payload the view asked for: sending both would read the same
            // placements twice for a reader who can see one of them.
            ->missing('list'));
});

it('sends the list payload for the list view and nothing of the board', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    attach(Task::factory()->in($workspace)->create(['title' => 'Write it down']), $project, $actor);

    $this->actingAs($actor)
        ->get(route('projects.show', ['project' => $project, 'view' => ProjectDefaultView::List->value]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('list.sections.0.tasks.0.title', 'Write it down')
            ->missing('board'));
});

it('sends the calendar payload for the calendar view and nothing of the other two', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->dueAt(CarbonImmutable::parse('2026-07-07 09:00'))->create(['title' => 'Ship it']);
    attach($task, $project, $actor);

    $this->actingAs($actor)
        ->get(route('projects.show', [
            'project' => $project,
            'view' => ProjectDefaultView::Calendar->value,
            'month' => '2026-07',
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('calendar.month', '2026-07')
            // The grid runs Monday to Sunday around the month, so July 2026 opens on 29 June.
            ->where('calendar.days.0.date', '2026-06-29')
            ->where('calendar.days.8.tasks.0.title', 'Ship it')
            ->missing('list')
            ->missing('board'));
});

it('opens the calendar on this month when the URL names none', function (): void {
    [, $project, $actor] = placeableProject();

    $this->actingAs($actor)
        ->get(route('projects.show', ['project' => $project, 'view' => ProjectDefaultView::Calendar->value]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('calendar.month', CarbonImmutable::now()->format('Y-m')));
});

it('refuses a month that is not one', function (): void {
    [, $project, $actor] = placeableProject();

    $this->actingAs($actor)
        ->get(route('projects.show', [
            'project' => $project,
            'view' => ProjectDefaultView::Calendar->value,
            'month' => 'julyish',
        ]))
        ->assertSessionHasErrors('month');
});

it('expands the column a reader asked for', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $column = Section::factory()->in($project)->create();

    foreach (range(1, ProjectBoardQuery::PER_COLUMN + 2) as $slot) {
        TaskProjectMembership::factory()
            ->placing(Task::factory()->in($workspace)->create(), $project)
            ->inSection($column)
            ->at($slot * SparsePosition::GAP)
            ->create();
    }

    $this->actingAs($actor)
        ->get(route('projects.show', [
            'project' => $project,
            'view' => ProjectDefaultView::Board->value,
            'expand' => [$column->id],
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('board.columns.0.tasks', ProjectBoardQuery::PER_COLUMN + 2)
            ->where('board.columns.0.hasMore', false));
});

it('lets the url override the project s own view for one request', function (): void {
    [, $project, $actor] = placeableProject();
    $project->forceFill(['default_view' => ProjectDefaultView::List])->save();

    $this->actingAs($actor)
        ->get(route('projects.show', ['project' => $project, 'view' => ProjectDefaultView::Board->value]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('view', ProjectDefaultView::Board->value));

    // Overridden for the request, not for the project: the URL is the state.
    expect($project->fresh()?->default_view)->toBe(ProjectDefaultView::List);
});

it('refuses a view that is neither', function (): void {
    [, $project, $actor] = placeableProject();

    // A typo that silently rendered the list would look like the switcher is broken.
    $this->actingAs($actor)
        ->get(route('projects.show', ['project' => $project, 'view' => 'gantt']))
        ->assertSessionHasErrors('view');
});

it('sends the permissions rather than leaving them to the client', function (): void {
    [$workspace, $project, $viewer] = placeableProject(ProjectAccessLevel::Viewer);
    TaskProjectMembership::factory()->placing(Task::factory()->in($workspace)->create(), $project)->create();

    $this->actingAs($viewer)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('list.can.createTask', false)
            ->where('list.can.updateTask', false)
            ->where('list.can.deleteTask', false)
            // Still shown what is there: a viewer reads the board they cannot change.
            ->has('list.sections.0.tasks', 1));
});

it('shows an archived project read-only rather than hiding it', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    attach(Task::factory()->in($workspace)->create(), $project, $actor);
    $project->forceFill(['archived_at' => now()])->save();

    $this->actingAs($actor)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('project.archived', true)
            ->where('list.can.createTask', false)
            ->has('list.sections.0.tasks', 1));
});

it('hides a private project from a workspace member who is not in it', function (): void {
    [$workspace] = placeableProject();
    $outsider = memberOf($workspace, WorkspaceRole::Member);
    $private = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Private]);

    // The binding resolves through the projects the actor can see, so this is a 404 and not
    // an empty board (ADR-0005).
    $this->actingAs($outsider)
        ->get(route('projects.show', $private))
        ->assertNotFound();
});

it('hides a project in another workspace', function (): void {
    [, , $actor] = placeableProject();
    [, $elsewhere] = placeableProject();

    $this->actingAs($actor)
        ->get(route('projects.show', $elsewhere))
        ->assertNotFound();
});

it('keeps a guest out of a project they were not given', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create(['visibility' => ProjectVisibility::Workspace]);

    // Workspace visibility never reaches a guest (ADR-0006).
    $this->actingAs($guest)
        ->get(route('projects.show', $project))
        ->assertNotFound();
});

it('sends the people a card can be handed to', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $member = memberOf($workspace, WorkspaceRole::Member);
    $pending = memberOf($workspace, WorkspaceRole::Member, WorkspaceMembershipStatus::Invited);

    $this->actingAs($actor)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($actor, $member, $pending): void {
            $ids = array_column($page->toArray()['props']['members'], 'id');

            // Active members only: somebody whose invitation is still pending cannot be
            // given work (TASK-060-012), so offering them would be offering a refusal.
            expect($ids)->toContain($actor->id)
                ->and($ids)->toContain($member->id)
                ->and($ids)->not->toContain($pending->id);
        });
});
