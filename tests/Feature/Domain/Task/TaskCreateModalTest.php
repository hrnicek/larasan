<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use Inertia\Testing\AssertableInertia;
use InertiaUI\Modal\Modal;

/*
 * The form behind the topbar's Create → Task, and behind the `+` on a section header. Its job is
 * to offer exactly the projects this person may put work into — which is a narrower question than
 * the projects they may read.
 */

it('offers only the projects the actor may add to', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    // Visible but read-only: a viewer may open this project and may not put work in it.
    $readable = Project::factory()->in($project->workspace)->create(['name' => 'Read only']);
    ProjectMembership::factory()->in($readable)->forUser($actor)->withAccess(ProjectAccessLevel::Viewer)->create();

    // Another tenant's project, which must not appear at all.
    Project::factory()->create(['name' => 'Somebody else']);

    $this->actingAs($actor)
        ->withHeader(Modal::HEADER_MODAL, 'modal-1')
        ->get(route('tasks.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('tasks/Create')
            ->has('projects', 1)
            ->where('projects.0.id', $project->id));
});

it('sends the chosen project its own sections and nobody else\'s', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $mine = Section::factory()->for($project)->create(['name' => 'Backlog']);

    $other = Project::factory()->in($project->workspace)->create();
    Section::factory()->for($other)->create(['name' => 'Not mine']);

    $this->actingAs($actor)
        ->withHeader(Modal::HEADER_MODAL, 'modal-1')
        ->get(route('tasks.create', ['project' => $project->id, 'section' => $mine->id]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('project', $project->id)
            ->where('section', $mine->id)
            ->has('sections', 1)
            ->where('sections.0.name', 'Backlog'));
});

it('sends no sections until a project is chosen', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    Section::factory()->for($project)->create();

    $this->actingAs($actor)
        ->withHeader(Modal::HEADER_MODAL, 'modal-1')
        ->get(route('tasks.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('project', null)
            ->has('sections', 0));
});

it('refuses an actor who may not create tasks in this workspace', function (): void {
    $workspace = Workspace::factory()->create();

    $this->actingAs(memberOf($workspace, WorkspaceRole::Guest))
        ->get(route('tasks.create'))
        ->assertForbidden();
});

it('renders a page behind it when the address is entered directly', function (): void {
    [, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->get(route('tasks.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Dashboard')
            ->where('_inertiaui_modal.component', 'tasks/Create')
            ->etc());
});
