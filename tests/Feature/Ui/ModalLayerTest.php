<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use Inertia\Testing\AssertableInertia;
use InertiaUI\Modal\Modal;

it('renders the base page underneath a modal entered by its own address', function (): void {
    [, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->get(route('projects.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('projects/Index')
            ->where('_inertiaui_modal.component', 'projects/Create')
            ->where('_inertiaui_modal.baseUrl', route('projects.index'))
            ->etc());
});

it('returns the modal alone when the application asks for one', function (): void {
    [, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->withHeader(Modal::HEADER_MODAL, 'modal-1')
        ->get(route('projects.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('projects/Create')
            ->missing('_inertiaui_modal'));
});

it('prefers the page the actor came from over the declared base route', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->withHeader('referer', route('projects.show', $project))
        ->get(route('projects.create'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('projects/Show')
            ->where('_inertiaui_modal.baseUrl', route('projects.show', $project))
            ->etc());
});

it('authorizes the modal itself rather than trusting the base page', function (): void {
    [$project, $owner] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $guest = memberOf($project->workspace, WorkspaceRole::Guest);

    $this->actingAs($owner)
        ->withHeader('referer', route('projects.show', $project))
        ->get(route('projects.create'))
        ->assertOk();

    $this->actingAs($guest)
        ->withHeader('referer', route('projects.show', $project))
        ->get(route('projects.create'))
        ->assertForbidden();
});

// Inertia v3 renders `data-page` from `SsrState`, not the view data `assertInertia` reads, so the markup is asserted.
it('renders the modal address into the page the browser actually reads', function (): void {
    [, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $response = $this->actingAs($actor)->get(route('projects.create'));

    preg_match(
        '/<script data-page="app" type="application\\/json">(.*?)<\\/script>/s',
        (string) $response->getContent(),
        $matches,
    );

    $page = json_decode(html_entity_decode($matches[1] ?? ''), associative: true);

    expect($page)
        ->toHaveKey('url', '/projects/create')
        ->and($page['component'])->toBe('projects/Index')
        ->and($page['props']['_inertiaui_modal']['component'])->toBe('projects/Create');
});
