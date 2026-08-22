<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/*
 * The matrix: every workspace role against every project access level at every protected
 * project endpoint, allowed and denied. `ProjectPolicyTest` proves the rules; this proves
 * the endpoints ask them, and that a refusal is the right *kind* of refusal — 404 where
 * the actor may not know the project exists, 403 where they may see it but not act.
 *
 * The expected outcome of every row is written out rather than derived. Deriving it from
 * `isManageableBy()` would assert that the code agrees with itself.
 */

/**
 * @return array{Project, User}
 */
function matrixProject(
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    ProjectVisibility $visibility = ProjectVisibility::Workspace,
): array {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create(['name' => 'Untouched', 'visibility' => $visibility]);

    if ($access !== null) {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();
    }

    return [$project, $actor];
}

/**
 * @return array{string, string, array<string, mixed>}
 */
function matrixProjectOperation(string $operation, Project $project): array
{
    return match ($operation) {
        'read settings' => ['get', route('projects.edit', $project), []],
        'update' => ['put', route('projects.update', $project), ['id' => $project->id, 'name' => 'Renamed']],
        'archive' => ['put', route('projects.archive', $project), []],
        'restore' => ['delete', route('projects.restore', $project), []],
        // A dataset row naming an operation this function does not know is a typo, and a
        // typo that silently ran nothing would look like a passing matrix.
        default => throw new InvalidArgumentException("Unknown matrix operation [{$operation}]."),
    };
}

it('answers each role and access level the same way at every endpoint', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    string $operation,
    string $outcome,
): void {
    [$project, $actor] = matrixProject($role, $access);

    [$method, $url, $payload] = matrixProjectOperation($operation, $project);

    $response = $this->actingAs($actor)->{$method}($url, $payload);

    match ($outcome) {
        'allowed' => expect($response->status())->toBeIn([200, 302]),
        'forbidden' => $response->assertForbidden(),
        'missing' => $response->assertNotFound(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };

    if ($outcome !== 'allowed') {
        expect($project->fresh()?->name)->toBe('Untouched')
            ->and($project->fresh()?->isArchived())->toBeFalse();
    }
})->with([
    'owner as owner read settings' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'read settings', 'allowed'],
    'owner as owner update' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'update', 'allowed'],
    'owner as owner archive' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'archive', 'allowed'],
    'owner as editor read settings' => [WorkspaceRole::Owner, ProjectAccessLevel::Editor, 'read settings', 'allowed'],
    'owner as editor update' => [WorkspaceRole::Owner, ProjectAccessLevel::Editor, 'update', 'forbidden'],
    'owner as editor archive' => [WorkspaceRole::Owner, ProjectAccessLevel::Editor, 'archive', 'forbidden'],
    'owner as commenter read settings' => [WorkspaceRole::Owner, ProjectAccessLevel::Commenter, 'read settings', 'allowed'],
    'owner as commenter update' => [WorkspaceRole::Owner, ProjectAccessLevel::Commenter, 'update', 'forbidden'],
    'owner as commenter archive' => [WorkspaceRole::Owner, ProjectAccessLevel::Commenter, 'archive', 'forbidden'],
    'owner as viewer read settings' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'read settings', 'allowed'],
    'owner as viewer update' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'update', 'forbidden'],
    'owner as viewer archive' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'archive', 'forbidden'],
    'owner as no member read settings' => [WorkspaceRole::Owner, null, 'read settings', 'allowed'],
    'owner as no member update' => [WorkspaceRole::Owner, null, 'update', 'forbidden'],
    'owner as no member archive' => [WorkspaceRole::Owner, null, 'archive', 'forbidden'],
    'admin as owner read settings' => [WorkspaceRole::Admin, ProjectAccessLevel::Owner, 'read settings', 'allowed'],
    'admin as owner update' => [WorkspaceRole::Admin, ProjectAccessLevel::Owner, 'update', 'allowed'],
    'admin as owner archive' => [WorkspaceRole::Admin, ProjectAccessLevel::Owner, 'archive', 'allowed'],
    'admin as editor read settings' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'read settings', 'allowed'],
    'admin as editor update' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'update', 'forbidden'],
    'admin as editor archive' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'archive', 'forbidden'],
    'admin as commenter read settings' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'read settings', 'allowed'],
    'admin as commenter update' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'update', 'forbidden'],
    'admin as commenter archive' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'archive', 'forbidden'],
    'admin as viewer read settings' => [WorkspaceRole::Admin, ProjectAccessLevel::Viewer, 'read settings', 'allowed'],
    'admin as viewer update' => [WorkspaceRole::Admin, ProjectAccessLevel::Viewer, 'update', 'forbidden'],
    'admin as viewer archive' => [WorkspaceRole::Admin, ProjectAccessLevel::Viewer, 'archive', 'forbidden'],
    'admin as no member read settings' => [WorkspaceRole::Admin, null, 'read settings', 'allowed'],
    'admin as no member update' => [WorkspaceRole::Admin, null, 'update', 'forbidden'],
    'admin as no member archive' => [WorkspaceRole::Admin, null, 'archive', 'forbidden'],
    'member as owner read settings' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'read settings', 'allowed'],
    'member as owner update' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'update', 'allowed'],
    'member as owner archive' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'archive', 'allowed'],
    'member as editor read settings' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'read settings', 'allowed'],
    'member as editor update' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'update', 'forbidden'],
    'member as editor archive' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'archive', 'forbidden'],
    'member as commenter read settings' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'read settings', 'allowed'],
    'member as commenter update' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'update', 'forbidden'],
    'member as commenter archive' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'archive', 'forbidden'],
    'member as viewer read settings' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'read settings', 'allowed'],
    'member as viewer update' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'update', 'forbidden'],
    'member as viewer archive' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'archive', 'forbidden'],
    'member as no member read settings' => [WorkspaceRole::Member, null, 'read settings', 'allowed'],
    'member as no member update' => [WorkspaceRole::Member, null, 'update', 'forbidden'],
    'member as no member archive' => [WorkspaceRole::Member, null, 'archive', 'forbidden'],
    'guest as owner read settings' => [WorkspaceRole::Guest, ProjectAccessLevel::Owner, 'read settings', 'allowed'],
    'guest as owner update' => [WorkspaceRole::Guest, ProjectAccessLevel::Owner, 'update', 'forbidden'],
    'guest as owner archive' => [WorkspaceRole::Guest, ProjectAccessLevel::Owner, 'archive', 'forbidden'],
    'guest as editor read settings' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'read settings', 'allowed'],
    'guest as editor update' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'update', 'forbidden'],
    'guest as editor archive' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'archive', 'forbidden'],
    'guest as commenter read settings' => [WorkspaceRole::Guest, ProjectAccessLevel::Commenter, 'read settings', 'allowed'],
    'guest as commenter update' => [WorkspaceRole::Guest, ProjectAccessLevel::Commenter, 'update', 'forbidden'],
    'guest as commenter archive' => [WorkspaceRole::Guest, ProjectAccessLevel::Commenter, 'archive', 'forbidden'],
    'guest as viewer read settings' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'read settings', 'allowed'],
    'guest as viewer update' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'update', 'forbidden'],
    'guest as viewer archive' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'archive', 'forbidden'],
    'guest as no member read settings' => [WorkspaceRole::Guest, null, 'read settings', 'missing'],
    'guest as no member update' => [WorkspaceRole::Guest, null, 'update', 'missing'],
    'guest as no member archive' => [WorkspaceRole::Guest, null, 'archive', 'missing'],
]);

it('hides a private project from a workspace member who was never given it', function (string $operation): void {
    [$project, $actor] = matrixProject(WorkspaceRole::Owner, null, ProjectVisibility::Private);

    [$method, $url, $payload] = matrixProjectOperation($operation, $project);

    /*
     * The workspace owner, on a private project in their own workspace. Private means
     * private: ownership of the workspace is not a key to every room in it.
     */
    $this->actingAs($actor)->{$method}($url, $payload)->assertNotFound();

    expect($project->fresh()?->name)->toBe('Untouched');
})->with(['read settings', 'update', 'archive']);

it('denies a guest by visibility as well as by access level', function (): void {
    [$project, $guest] = matrixProject(WorkspaceRole::Guest, null);

    // The project is workspace-visible, which grants a guest nothing at all.
    $this->actingAs($guest)->get(route('projects.edit', $project))->assertNotFound();

    ProjectMembership::factory()->in($project)->forUser($guest)->withAccess(ProjectAccessLevel::Owner)->create();

    // Given the project explicitly, the guest may read it — and still may not manage it,
    // because the workspace half of the answer says a guest never updates a project.
    $this->actingAs($guest)->get(route('projects.edit', $project))->assertOk();
    $this->actingAs($guest)
        ->put(route('projects.update', $project), ['id' => $project->id, 'name' => 'Renamed'])
        ->assertForbidden();

    expect($project->fresh()?->name)->toBe('Untouched');
});

it('hides a project from another workspace on read and on write', function (string $operation): void {
    [$project] = matrixProject(WorkspaceRole::Owner, ProjectAccessLevel::Owner);
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    [$method, $url, $payload] = matrixProjectOperation($operation, $project);

    $this->actingAs($outsider)->{$method}($url, $payload)->assertNotFound();

    expect($project->fresh()?->name)->toBe('Untouched')
        ->and($project->fresh()?->isArchived())->toBeFalse();
})->with(['read settings', 'update', 'archive', 'restore']);

it('keeps another workspace out of the project listing and the sidebar', function (): void {
    [$project, $actor] = matrixProject(WorkspaceRole::Owner, ProjectAccessLevel::Owner);
    Project::factory()->create(['name' => 'Theirs']);

    $this->actingAs($actor)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSee($project->name)
        ->assertDontSee('Theirs');
});

it('refuses every project endpoint to an unauthenticated visitor', function (string $operation): void {
    [$project] = matrixProject(WorkspaceRole::Owner, ProjectAccessLevel::Owner);

    [$method, $url, $payload] = matrixProjectOperation($operation, $project);

    $this->{$method}($url, $payload)->assertRedirect(route('login'));

    expect($project->fresh()?->name)->toBe('Untouched');
})->with(['read settings', 'update', 'archive', 'restore']);
