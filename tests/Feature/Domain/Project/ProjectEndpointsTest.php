<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('requires authentication', function (): void {
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});

it('lists the projects the actor may see in the current workspace', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    Project::factory()->in($project->workspace)->private()->create(['name' => 'Secret']);
    Project::factory()->create(['name' => 'Another workspace']);

    $this->actingAs($actor)
        ->get(route('projects.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('projects/Index')
            ->has('allProjects', 1)
            ->where('allProjects.0.id', $project->id)
            ->where('can.create', true));
});

it('excludes archived projects from the listing', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $project->forceFill(['archived_at' => now()])->save();

    $this->actingAs($actor)
        ->get(route('projects.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('allProjects', 0));
});

it('refuses the creation screen to an actor without the capability', function (): void {
    $workspace = Workspace::factory()->create();

    $this->actingAs(memberOf($workspace, WorkspaceRole::Guest))
        ->get(route('projects.create'))
        ->assertForbidden();
});

it('offers the access levels on the creation screen', function (): void {
    $workspace = Workspace::factory()->create();

    $this->actingAs(memberOf($workspace, WorkspaceRole::Member))
        ->get(route('projects.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('_inertiaui_modal.props.options.visibilities', array_column(ProjectVisibility::cases(), 'value'))
            ->etc());
});

it('creates a project and lands on its settings', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    $response = $this->actingAs($actor)->post(route('projects.store'), ['name' => 'Web Redesign']);

    $project = Project::query()->where('workspace_id', $workspace->id)->sole();

    $response->assertRedirect(route('projects.edit', $project));
    expect($project->memberFor($actor)?->access_level)->toBe(ProjectAccessLevel::Owner);
});

it('shows the settings screen with the abilities the actor has', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->get(route('projects.edit', $project))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('projects/Settings')
            ->where('project.slug', $project->slug)
            ->where('can.update', true)
            ->where('can.manageMembers', true));
});

it('sends the project sections in order with the ability to add one', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    Section::factory()->in($project)->at(2 * Section::POSITION_GAP)->create(['name' => 'Second']);
    Section::factory()->in($project)->at(Section::POSITION_GAP)->create(['name' => 'First']);

    $this->actingAs($actor)
        ->get(route('projects.edit', $project))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('sections', 2)
            ->where('sections.0.name', 'First')
            ->where('sections.1.name', 'Second')
            ->where('can.createSection', true));
});

it('tells a viewer they may not add a section', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);

    $this->actingAs($actor)
        ->get(route('projects.edit', $project))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('can.createSection', false));
});

it('sends the enum options the settings form offers', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->get(route('projects.edit', $project))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('options.visibilities', array_column(ProjectVisibility::cases(), 'value'))
            ->where('options.views', array_column(ProjectDefaultView::cases(), 'value'))
            ->where('options.colors', array_column(ProjectColor::cases(), 'value')));
});

it('renders the settings screen read-only for someone who may see but not manage', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);

    $this->actingAs($actor)
        ->get(route('projects.edit', $project))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('can.update', false)
            ->where('can.archive', false)
            ->where('can.delete', false));
});

it('updates a project through its settings', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->put(route('projects.update', $project), [
            'id' => $project->id,
            'name' => 'Web Redesign',
            'visibility' => ProjectVisibility::Private->value,
        ])
        ->assertRedirect(route('projects.edit', $project));

    expect($project->fresh()?->name)->toBe('Web Redesign')
        ->and($project->fresh()?->visibility)->toBe(ProjectVisibility::Private);
});

it('archives and restores a project', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)->put(route('projects.archive', $project))->assertRedirect(route('projects.edit', $project));
    expect($project->fresh()?->isArchived())->toBeTrue();

    $this->actingAs($actor)->delete(route('projects.restore', $project))->assertRedirect(route('projects.edit', $project));
    expect($project->fresh()?->isArchived())->toBeFalse();
});

it('refuses archiving to a member who may see the project but not manage it', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);

    $this->actingAs($actor)->put(route('projects.archive', $project))->assertForbidden();

    expect($project->fresh()?->isArchived())->toBeFalse();
});

it('keeps the settings screen reachable while a project is archived', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $project->forceFill(['archived_at' => now()])->save();

    $this->actingAs($actor)
        ->get(route('projects.edit', $project))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('project.archived', true));
});

it('hides a project from another workspace behind a 404', function (string $method, string $name): void {
    [, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $theirs = Project::factory()->create();

    $this->actingAs($actor)
        ->call($method, route($name, $theirs), ['id' => $theirs->id, 'name' => 'Renamed'])
        ->assertNotFound();
})->with([
    'read' => ['GET', 'projects.edit'],
    'write' => ['PUT', 'projects.update'],
    'archive' => ['PUT', 'projects.archive'],
]);

it('hides a private project from a workspace member who was never given it', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->private()->create();

    $this->actingAs($actor)->get(route('projects.edit', $project))->assertNotFound();
});

it('refuses an update to a member who may see the project but not manage it', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);

    $this->actingAs($actor)
        ->put(route('projects.update', $project), ['id' => $project->id, 'name' => 'Renamed'])
        ->assertForbidden();

    expect($project->fresh()?->name)->not->toBe('Renamed');
});

it('shows a guest only the projects they were explicitly given', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $given = Project::factory()->in($workspace)->create(['name' => 'Given']);
    Project::factory()->in($workspace)->create(['name' => 'Workspace visible']);
    ProjectMembership::factory()->in($given)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();

    $this->actingAs($guest)
        ->get(route('projects.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('projects', 1)
            ->where('projects.0.id', $given->id)
            ->where('can.create', false));
});

it('rejects a malformed project id at routing', function (): void {
    [, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)->get('projects/not-a-uuid/settings')->assertNotFound();
});

it('throttles project creation', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);

    foreach (range(1, 20) as $index) {
        $this->actingAs($actor)->post(route('projects.store'), ['name' => "Project {$index}"])->assertRedirect();
    }

    $this->actingAs($actor)->post(route('projects.store'), ['name' => 'One too many'])->assertStatus(429);
});

it('requires a verified email address', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member, user: User::factory()->unverified()->create());

    $this->actingAs($actor)->get(route('projects.index'))->assertRedirect(route('verification.notice'));
});
