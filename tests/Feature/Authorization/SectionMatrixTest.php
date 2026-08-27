<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/*
 * Every workspace role against every project access level at every section endpoint. The
 * policy tests prove the rules; this proves the endpoints ask them, and that a refusal is
 * the right kind — 404 where the actor may not know the project exists, 403 where they may
 * see it but not shape it.
 *
 * Outcomes are written out rather than derived from `allowsChangesBy()`, which would
 * assert only that the code agrees with itself.
 */

/**
 * @return array{Section, User, Project}
 */
function matrixSection(
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    ProjectVisibility $visibility = ProjectVisibility::Workspace,
): array {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create(['visibility' => $visibility]);

    if ($access !== null) {
        ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();
    }

    $section = Section::factory()->in($project)->create(['name' => 'Untouched']);
    Section::factory()->in($project)->at(2 * Section::POSITION_GAP)->create(['name' => 'Anchor']);

    return [$section, $actor, $project];
}

/**
 * @return array{string, string, array<string, mixed>}
 */
function matrixSectionOperation(string $operation, Section $section, Project $project): array
{
    return match ($operation) {
        'add' => ['post', route('sections.store', $project), ['name' => 'Added']],
        'rename' => ['put', route('sections.update', $section), ['name' => 'Renamed']],
        'move' => ['put', route('sections.move', $section), ['after' => $project->sections()->where('name', 'Anchor')->value('id')]],
        'delete' => ['delete', route('sections.destroy', $section), []],
        default => throw new InvalidArgumentException("Unknown matrix operation [{$operation}]."),
    };
}

it('answers each role and access level the same way at every section endpoint', function (
    WorkspaceRole $role,
    ?ProjectAccessLevel $access,
    string $operation,
    string $outcome,
): void {
    [$section, $actor, $project] = matrixSection($role, $access);

    [$method, $url, $payload] = matrixSectionOperation($operation, $section, $project);

    $response = $this->actingAs($actor)->{$method}($url, $payload);

    match ($outcome) {
        'allowed' => expect($response->status())->toBeIn([200, 302]),
        'forbidden' => $response->assertForbidden(),
        'missing' => $response->assertNotFound(),
        default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
    };

    if ($outcome !== 'allowed') {
        expect($section->fresh()?->name)->toBe('Untouched')
            ->and($project->sections()->count())->toBe(2);
    }
})->with([
    'owner as owner add' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'add', 'allowed'],
    'owner as owner rename' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'rename', 'allowed'],
    'owner as owner move' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'move', 'allowed'],
    'owner as owner delete' => [WorkspaceRole::Owner, ProjectAccessLevel::Owner, 'delete', 'allowed'],
    'owner as editor add' => [WorkspaceRole::Owner, ProjectAccessLevel::Editor, 'add', 'allowed'],
    'owner as editor rename' => [WorkspaceRole::Owner, ProjectAccessLevel::Editor, 'rename', 'allowed'],
    'owner as editor move' => [WorkspaceRole::Owner, ProjectAccessLevel::Editor, 'move', 'allowed'],
    'owner as editor delete' => [WorkspaceRole::Owner, ProjectAccessLevel::Editor, 'delete', 'allowed'],
    'owner as commenter add' => [WorkspaceRole::Owner, ProjectAccessLevel::Commenter, 'add', 'forbidden'],
    'owner as commenter rename' => [WorkspaceRole::Owner, ProjectAccessLevel::Commenter, 'rename', 'forbidden'],
    'owner as commenter move' => [WorkspaceRole::Owner, ProjectAccessLevel::Commenter, 'move', 'forbidden'],
    'owner as commenter delete' => [WorkspaceRole::Owner, ProjectAccessLevel::Commenter, 'delete', 'forbidden'],
    'owner as viewer add' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'add', 'forbidden'],
    'owner as viewer rename' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'rename', 'forbidden'],
    'owner as viewer move' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'move', 'forbidden'],
    'owner as viewer delete' => [WorkspaceRole::Owner, ProjectAccessLevel::Viewer, 'delete', 'forbidden'],
    /*
     * No membership row, on a project the whole workspace can open. These were refused until
     * TASK-260-001: `visibility` had answered only the read half of ADR-0006, so a board
     * everybody could see was one nobody but its named members could shape — the workspace
     * owner included. The project's `default_access_level` answers now, and it is `editor`.
     * A guest is still refused: they hold projects, never a default.
     */
    'owner as no member add' => [WorkspaceRole::Owner, null, 'add', 'allowed'],
    'owner as no member rename' => [WorkspaceRole::Owner, null, 'rename', 'allowed'],
    'owner as no member move' => [WorkspaceRole::Owner, null, 'move', 'allowed'],
    'owner as no member delete' => [WorkspaceRole::Owner, null, 'delete', 'allowed'],
    'admin as owner add' => [WorkspaceRole::Admin, ProjectAccessLevel::Owner, 'add', 'allowed'],
    'admin as owner rename' => [WorkspaceRole::Admin, ProjectAccessLevel::Owner, 'rename', 'allowed'],
    'admin as owner move' => [WorkspaceRole::Admin, ProjectAccessLevel::Owner, 'move', 'allowed'],
    'admin as owner delete' => [WorkspaceRole::Admin, ProjectAccessLevel::Owner, 'delete', 'allowed'],
    'admin as editor add' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'add', 'allowed'],
    'admin as editor rename' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'rename', 'allowed'],
    'admin as editor move' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'move', 'allowed'],
    'admin as editor delete' => [WorkspaceRole::Admin, ProjectAccessLevel::Editor, 'delete', 'allowed'],
    'admin as commenter add' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'add', 'forbidden'],
    'admin as commenter rename' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'rename', 'forbidden'],
    'admin as commenter move' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'move', 'forbidden'],
    'admin as commenter delete' => [WorkspaceRole::Admin, ProjectAccessLevel::Commenter, 'delete', 'forbidden'],
    'admin as viewer add' => [WorkspaceRole::Admin, ProjectAccessLevel::Viewer, 'add', 'forbidden'],
    'admin as viewer rename' => [WorkspaceRole::Admin, ProjectAccessLevel::Viewer, 'rename', 'forbidden'],
    'admin as viewer move' => [WorkspaceRole::Admin, ProjectAccessLevel::Viewer, 'move', 'forbidden'],
    'admin as viewer delete' => [WorkspaceRole::Admin, ProjectAccessLevel::Viewer, 'delete', 'forbidden'],
    'admin as no member add' => [WorkspaceRole::Admin, null, 'add', 'allowed'],
    'admin as no member rename' => [WorkspaceRole::Admin, null, 'rename', 'allowed'],
    'admin as no member move' => [WorkspaceRole::Admin, null, 'move', 'allowed'],
    'admin as no member delete' => [WorkspaceRole::Admin, null, 'delete', 'allowed'],
    'member as owner add' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'add', 'allowed'],
    'member as owner rename' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'rename', 'allowed'],
    'member as owner move' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'move', 'allowed'],
    'member as owner delete' => [WorkspaceRole::Member, ProjectAccessLevel::Owner, 'delete', 'allowed'],
    'member as editor add' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'add', 'allowed'],
    'member as editor rename' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'rename', 'allowed'],
    'member as editor move' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'move', 'allowed'],
    'member as editor delete' => [WorkspaceRole::Member, ProjectAccessLevel::Editor, 'delete', 'allowed'],
    'member as commenter add' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'add', 'forbidden'],
    'member as commenter rename' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'rename', 'forbidden'],
    'member as commenter move' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'move', 'forbidden'],
    'member as commenter delete' => [WorkspaceRole::Member, ProjectAccessLevel::Commenter, 'delete', 'forbidden'],
    'member as viewer add' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'add', 'forbidden'],
    'member as viewer rename' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'rename', 'forbidden'],
    'member as viewer move' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'move', 'forbidden'],
    'member as viewer delete' => [WorkspaceRole::Member, ProjectAccessLevel::Viewer, 'delete', 'forbidden'],
    'member as no member add' => [WorkspaceRole::Member, null, 'add', 'allowed'],
    'member as no member rename' => [WorkspaceRole::Member, null, 'rename', 'allowed'],
    'member as no member move' => [WorkspaceRole::Member, null, 'move', 'allowed'],
    'member as no member delete' => [WorkspaceRole::Member, null, 'delete', 'allowed'],
    'guest as owner add' => [WorkspaceRole::Guest, ProjectAccessLevel::Owner, 'add', 'forbidden'],
    'guest as owner rename' => [WorkspaceRole::Guest, ProjectAccessLevel::Owner, 'rename', 'forbidden'],
    'guest as owner move' => [WorkspaceRole::Guest, ProjectAccessLevel::Owner, 'move', 'forbidden'],
    'guest as owner delete' => [WorkspaceRole::Guest, ProjectAccessLevel::Owner, 'delete', 'forbidden'],
    'guest as editor add' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'add', 'forbidden'],
    'guest as editor rename' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'rename', 'forbidden'],
    'guest as editor move' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'move', 'forbidden'],
    'guest as editor delete' => [WorkspaceRole::Guest, ProjectAccessLevel::Editor, 'delete', 'forbidden'],
    'guest as commenter add' => [WorkspaceRole::Guest, ProjectAccessLevel::Commenter, 'add', 'forbidden'],
    'guest as commenter rename' => [WorkspaceRole::Guest, ProjectAccessLevel::Commenter, 'rename', 'forbidden'],
    'guest as commenter move' => [WorkspaceRole::Guest, ProjectAccessLevel::Commenter, 'move', 'forbidden'],
    'guest as commenter delete' => [WorkspaceRole::Guest, ProjectAccessLevel::Commenter, 'delete', 'forbidden'],
    'guest as viewer add' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'add', 'forbidden'],
    'guest as viewer rename' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'rename', 'forbidden'],
    'guest as viewer move' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'move', 'forbidden'],
    'guest as viewer delete' => [WorkspaceRole::Guest, ProjectAccessLevel::Viewer, 'delete', 'forbidden'],
    'guest as no member add' => [WorkspaceRole::Guest, null, 'add', 'missing'],
    'guest as no member rename' => [WorkspaceRole::Guest, null, 'rename', 'missing'],
    'guest as no member move' => [WorkspaceRole::Guest, null, 'move', 'missing'],
    'guest as no member delete' => [WorkspaceRole::Guest, null, 'delete', 'missing'],
]);

it('hides a private project section from the workspace owner who was never given it', function (string $operation): void {
    [$section, $actor, $project] = matrixSection(WorkspaceRole::Owner, null, ProjectVisibility::Private);

    [$method, $url, $payload] = matrixSectionOperation($operation, $section, $project);

    $this->actingAs($actor)->{$method}($url, $payload)->assertNotFound();

    expect($section->fresh()?->name)->toBe('Untouched');
})->with(['add', 'rename', 'move', 'delete']);

it('hides a section from another workspace at every endpoint', function (string $operation): void {
    [$section, , $project] = matrixSection(WorkspaceRole::Owner, ProjectAccessLevel::Owner);
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    [$method, $url, $payload] = matrixSectionOperation($operation, $section, $project);

    $this->actingAs($outsider)->{$method}($url, $payload)->assertNotFound();

    expect($section->fresh()?->name)->toBe('Untouched')
        ->and($project->sections()->count())->toBe(2);
})->with(['add', 'rename', 'move', 'delete']);

it('redirects an unauthenticated visitor from every section endpoint', function (string $operation): void {
    [$section, , $project] = matrixSection(WorkspaceRole::Owner, ProjectAccessLevel::Owner);

    [$method, $url, $payload] = matrixSectionOperation($operation, $section, $project);

    $this->{$method}($url, $payload)->assertRedirect(route('login'));

    expect($section->fresh()?->name)->toBe('Untouched');
})->with(['add', 'rename', 'move', 'delete']);
