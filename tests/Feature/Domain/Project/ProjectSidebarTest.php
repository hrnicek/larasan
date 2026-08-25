<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

it('shares only the projects the actor may see in the current workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $visible = Project::factory()->in($workspace)->create(['name' => 'Visible']);
    Project::factory()->in($workspace)->private()->create(['name' => 'Private']);
    Project::factory()->create(['name' => 'Another workspace']);

    $this->actingAs($actor)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('projects', 1)
            ->where('projects.0.id', $visible->id)
            ->where('projects.0.color', null));
});

it('keeps archived projects out of the sidebar', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    Project::factory()->in($workspace)->archived()->create();

    $this->actingAs($actor)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('projects', 0));
});

it('caps the shared list and leaves the rest to the project index', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    Project::factory()->in($workspace)->count(16)->create();

    $this->actingAs($actor)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('projects', 15));
});

it('shares a guest only the projects they were explicitly given', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $given = Project::factory()->in($workspace)->create(['name' => 'Given']);
    Project::factory()->in($workspace)->create(['name' => 'Workspace visible']);
    ProjectMembership::factory()->in($given)->forUser($guest)->withAccess(ProjectAccessLevel::Viewer)->create();

    $this->actingAs($guest)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('projects', 1)
            ->where('projects.0.id', $given->id)
            ->where('auth.capabilities', fn (Collection $capabilities): bool => ! $capabilities->contains(Capability::ProjectCreate->value)));
});

it('shares an empty list to someone with no workspace', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('projects', 0));
});

it('reads the sidebar list in one query per request', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    Project::factory()->in($workspace)->count(3)->create();

    DB::enableQueryLog();

    $this->actingAs($actor)->get(route('dashboard'))->assertOk();

    $projectQueries = array_filter(
        DB::getQueryLog(),
        fn (array $query): bool => str_contains((string) $query['query'], 'from "projects"'),
    );

    expect($projectQueries)->toHaveCount(1);
});

/*
 * The row's own menu renders two abilities per project, and the policy answers them from a
 * membership row. Asked project by project that is one read each — the shape the comment on
 * `MembershipRegistry::preloadProjects()` describes, and the reason it exists.
 */
it('answers the menu\'s permissions for the whole list in one more query', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    Project::factory()->in($workspace)->count(5)->create();

    DB::enableQueryLog();

    $this->actingAs($actor)->get(route('dashboard'))->assertOk();

    // The visible-projects query names the table in an `exists` subquery of its own, so a read
    // of the memberships is one that is not also a read of the projects.
    $membershipQueries = array_filter(
        DB::getQueryLog(),
        fn (array $query): bool => str_contains((string) $query['query'], 'from "project_memberships"')
            && ! str_contains((string) $query['query'], 'from "projects"'),
    );

    expect($membershipQueries)->toHaveCount(1);
});

it('sends each row the abilities its menu draws itself on', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Member);
    $managed = Project::factory()->in($workspace)->create(['name' => 'A managed one']);
    Project::factory()->in($workspace)->create(['name' => 'B readable one']);
    ProjectMembership::factory()->in($managed)->forUser($actor)->withAccess(ProjectAccessLevel::Owner)->create();

    $this->actingAs($actor)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('projects.0.canUpdate', true)
            ->where('projects.0.canArchive', true)
            // Visible through `visibility = workspace`, with no membership row of their own.
            ->where('projects.1.canUpdate', false)
            ->where('projects.1.canArchive', false));
});

/*
 * The other half of the same question: the workspace capability. An access level of Owner in the
 * project grants nothing to somebody whose role cannot change projects at all (ADR-0006).
 */
it('refuses the menu to a role that cannot change projects, whatever the access level says', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create();
    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Owner)->create();

    $this->actingAs($guest)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('projects.0.canUpdate', false)
            ->where('projects.0.canArchive', false));
});
