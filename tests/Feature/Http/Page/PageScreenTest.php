<?php

declare(strict_types=1);

use App\Domain\Page\Models\Page;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use Inertia\Testing\AssertableInertia;

it('renders a page with the document, the version and the project tree', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->titled('Brief')->create(['version' => 3]);
    $child = Page::factory()->under($page)->create();

    $this->actingAs($actor)
        ->get(route('pages.show', $page))
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->component('pages/Show')
            ->where('page.id', $page->id)
            ->where('page.title', 'Brief')
            ->where('page.version', 3)
            ->where('page.content.type', 'doc')
            ->where('project.id', $project->id)
            ->where('pages.tree.0.id', $page->id)
            ->where('pages.tree.0.children.0.id', $child->id)
            ->where('pages.can.updatePage', true));
});

it('lands on the page it just created rather than back on the tree', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);

    $this->actingAs($actor)
        ->post(route('projects.pages.store', $project), ['title' => 'Brief'])
        ->assertRedirect(route('pages.show', Page::query()->where('project_id', $project->id)->firstOrFail()));
});

it('says who last changed the page, by name and nothing else', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create(['updated_by' => $actor->id]);

    $this->actingAs($actor)
        ->get(route('pages.show', $page))
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('page.updatedBy', $actor->name)
            ->missing('page.editor'));
});

it('says nothing about an editor who has left rather than inventing one', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create(['updated_by' => null]);

    $this->actingAs($actor)
        ->get(route('pages.show', $page))
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('page.updatedBy', null));
});

it('lets a viewer read a page and tells the screen they may not write it', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);
    $page = Page::factory()->in($project)->create();

    $this->actingAs($actor)
        ->get(route('pages.show', $page))
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('pages.can.updatePage', false)
            ->where('pages.can.deletePage', false));
});

it('hides a page of a project the actor cannot open', function (): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor, ProjectVisibility::Private);
    $page = Page::factory()->in($project)->create();
    $outsider = memberOf($project->workspace, WorkspaceRole::Member);

    $this->actingAs($outsider)->get(route('pages.show', $page))->assertNotFound();
});

it('hides a page of another workspace', function (): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();
    [, $stranger] = workspaceWith(WorkspaceRole::Owner);

    $this->actingAs($stranger)->get(route('pages.show', $page))->assertNotFound();
});

it('sends a deleted page nowhere', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();
    $page->delete();

    $this->actingAs($actor)->get(route('pages.show', $page))->assertNotFound();
});

it('turns an anonymous reader away', function (): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $page = Page::factory()->in($project)->create();

    $this->get(route('pages.show', $page))->assertRedirect(route('login'));
});
