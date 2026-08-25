<?php

declare(strict_types=1);

use App\Domain\Page\Models\Page;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use Inertia\Testing\AssertableInertia;

it('draws the project as its pages when the URL asks for them', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->titled('Brief')->create(['excerpt' => 'The first line']);

    $this->actingAs($actor)
        ->get(route('projects.show', [$project, 'view' => 'pages']))
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->component('projects/Show')
            ->where('view', 'pages')
            ->where('pages.tree.0.id', $page->id)
            ->where('pages.tree.0.title', 'Brief')
            ->where('pages.tree.0.excerpt', 'The first line')
            ->where('pages.can.createPage', true)
            ->missing('list')
            ->missing('board'));
});

it('sends the tree as a tree, in order', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $second = Page::factory()->in($project)->at(SparsePosition::GAP * 2)->titled('Second')->create();
    $first = Page::factory()->in($project)->at(SparsePosition::GAP)->titled('First')->create();
    $child = Page::factory()->under($first)->titled('Child')->create();

    $this->actingAs($actor)
        ->get(route('projects.show', [$project, 'view' => 'pages']))
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('pages.tree.0.id', $first->id)
            ->where('pages.tree.0.children.0.id', $child->id)
            ->where('pages.tree.1.id', $second->id)
            ->where('pages.tree.1.children', []));
});

it('never sends the documents themselves to draw a list of them', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    Page::factory()->in($project)->create();

    $this->actingAs($actor)
        ->get(route('projects.show', [$project, 'view' => 'pages']))
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia->missing('pages.tree.0.content'));
});

it('tells a viewer they may read the pages and write none of them', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);
    Page::factory()->in($project)->create();

    $this->actingAs($actor)
        ->get(route('projects.show', [$project, 'view' => 'pages']))
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('pages.can.createPage', false)
            ->where('pages.can.updatePage', false)
            ->where('pages.can.deletePage', false));
});

it('reads one tree in one query, whatever its depth', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);

    $parent = Page::factory()->in($project)->create();

    for ($level = 1; $level < Page::MAX_DEPTH; $level++) {
        $parent = Page::factory()->under($parent)->create();
    }

    $queries = 0;
    DB::listen(function (Illuminate\Database\Events\QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'from "pages"')) {
            $queries++;
        }
    });

    $this->actingAs($actor)->get(route('projects.show', [$project, 'view' => 'pages']))->assertOk();

    expect($queries)->toBe(1);
});

it('keeps the pages of a project the actor cannot open out of reach', function (): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor, ProjectVisibility::Private);
    Page::factory()->in($project)->create();
    $outsider = memberOf($project->workspace, WorkspaceRole::Member);

    $this->actingAs($outsider)
        ->get(route('projects.show', [$project, 'view' => 'pages']))
        ->assertNotFound();
});

it('refuses a view nobody built', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);

    $this->actingAs($actor)
        ->get(route('projects.show', [$project, 'view' => 'wiki']))
        ->assertSessionHasErrors('view');
});
