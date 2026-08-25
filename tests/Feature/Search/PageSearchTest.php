<?php

declare(strict_types=1);

use App\Domain\Page\Models\Page;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Search\Queries\PageResults;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/**
 * @return list<array<string, mixed>>
 */
function pageResults(Workspace $workspace, User $actor, string $term, int $limit = 5): array
{
    return app(PageResults::class)($workspace, $actor, $term, $limit);
}

it('finds a page by its title', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();

    Page::factory()->in($project)->titled('Launch brief')->create();
    Page::factory()->in($project)->titled('Retrospective')->create();

    $results = pageResults($workspace, $actor, 'launch');

    expect($results)->toHaveCount(1)
        ->and($results[0]['title'])->toBe('Launch brief');
});

it('finds a page by what is written in it', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();

    Page::factory()->in($project)->titled('Notes')->create([
        'content' => doc([['type' => 'paragraph', 'content' => [textNode('The pricing model we settled on')]]]),
    ]);

    expect(pageResults($workspace, $actor, 'pricing'))->toHaveCount(1);
});

it('indexes the words rather than the document around them', function (): void {
    $page = Page::factory()->create([
        'content' => doc([['type' => 'paragraph', 'content' => [textNode('Only these words')]]]),
    ]);

    expect($page->toSearchableArray())
        ->toHaveKey('text', 'Only these words')
        ->toHaveKey('workspace_id', $page->project->workspace_id)
        ->toHaveKey('project_id', $page->project_id)
        ->not->toHaveKey('content');
});

it('carries the project a page was written in, because titles repeat', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create(['name' => 'Website relaunch']);
    Page::factory()->in($project)->titled('Brief')->create();

    expect(pageResults($workspace, $actor, 'brief')[0]['project'])
        ->toMatchArray(['id' => $project->id, 'name' => 'Website relaunch']);
});

it('never returns a page from a private project the actor was not given', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->private()->create();
    Page::factory()->in($project)->titled('Acquisition brief')->create();

    expect(pageResults($workspace, $actor, 'acquisition'))->toBe([]);
});

it('returns a page of a private project to the member who holds it', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->private()->create();
    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess(ProjectAccessLevel::Viewer)->create();
    Page::factory()->in($project)->titled('Acquisition brief')->create();

    expect(pageResults($workspace, $actor, 'acquisition'))->toHaveCount(1);
});

it('never returns a page from another workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Page::factory()->in(Project::factory()->create())->titled('Someone else’s brief')->create();

    expect(pageResults($workspace, $actor, 'brief'))->toBe([]);
});

it('never returns a page a guest was never given', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $project = Project::factory()->in($workspace)->create();
    Page::factory()->in($project)->titled('Launch brief')->create();

    expect(pageResults($workspace, $guest, 'launch'))->toBe([]);
});

it('leaves a removed page out of the answer', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $project = Project::factory()->in($workspace)->create();
    $page = Page::factory()->in($project)->titled('Launch brief')->create();

    $page->delete();

    expect(pageResults($workspace, $actor, 'launch'))->toBe([]);
});

it('answers an empty term with nothing rather than with everything', function (string $term): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    Page::factory()->in(Project::factory()->in($workspace)->create())->create();

    expect(pageResults($workspace, $actor, $term))->toBe([]);
})->with(['empty' => [''], 'whitespace' => ['   ']]);
