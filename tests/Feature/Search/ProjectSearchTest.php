<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Search\Queries\ProjectResults;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * @return list<array<string, mixed>>
 */
function projectResults(Workspace $workspace, User $actor, string $term, int $limit = 5): array
{
    return app(ProjectResults::class)($workspace, $actor, $term, $limit);
}

it('finds a project by its name', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Project::factory()->in($workspace)->create(['name' => 'Website relaunch']);
    Project::factory()->in($workspace)->create(['name' => 'Internal tools']);

    $results = projectResults($workspace, $actor, 'website');

    expect($results)->toHaveCount(1)
        ->and($results[0]['name'])->toBe('Website relaunch');
});

it('finds a project by the slug somebody pasted', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Project::factory()->in($workspace)->create(['name' => 'Website relaunch', 'slug' => 'website-relaunch']);

    expect(projectResults($workspace, $actor, 'website-relaunch'))->toHaveCount(1);
});

it('never returns a private project the actor was not given', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Project::factory()->in($workspace)->private()->create(['name' => 'Acquisition']);

    expect(projectResults($workspace, $actor, 'acquisition'))->toBe([]);
});

it('returns a private project to the member who holds it', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->private()->create(['name' => 'Acquisition']);
    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess(ProjectAccessLevel::Viewer)->create();

    expect(projectResults($workspace, $actor, 'acquisition'))->toHaveCount(1);
});

it('never returns a project from another workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Project::factory()->in(Workspace::factory()->create())->create(['name' => 'Website relaunch']);

    expect(projectResults($workspace, $actor, 'website'))->toBe([]);
});

it('gives a guest only the projects they were given', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    Project::factory()->in($workspace)->create(['name' => 'Website relaunch']);

    expect(projectResults($workspace, $guest, 'website'))->toBe([]);
});

it('finds an archived project and says that it is archived', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Project::factory()->in($workspace)->archived()->create(['name' => 'Website relaunch']);

    $results = projectResults($workspace, $actor, 'website');

    expect($results)->toHaveCount(1)
        ->and($results[0]['archived'])->toBeTrue();
});

it('returns nothing for an empty term', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Project::factory()->in($workspace)->create(['name' => 'Website relaunch']);

    expect(projectResults($workspace, $actor, ' '))->toBe([]);
});
