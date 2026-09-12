<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use Inertia\Testing\AssertableInertia;

it('answers an address that leads nowhere with its own page', function (): void {
    [$workspace, $actor] = workspaceWith(WorkspaceRole::Member);

    $this->actingAs($actor)
        ->get('/projects/'.Str::uuid7())
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Error')
            ->where('status', 404));
})->with([
    'the status is kept: a page rendered with 200 would tell a crawler this address works',
]);

it('answers a refusal with its own page', function (): void {
    $workspace = Workspace::factory()->create();
    $guest = memberOf($workspace, WorkspaceRole::Guest);

    $this->actingAs($guest)
        ->get(route('projects.create'))
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Error')
            ->where('status', 403));
});

it('keeps the shared props the shell needs', function (): void {
    [, $actor] = workspaceWith(WorkspaceRole::Member);

    $this->actingAs($actor)
        ->get('/projects/'.Str::uuid7())
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Error')
            ->has('auth.user'));
})->with([
    'a 404 happens outside the Inertia middleware, so the shared data is resolved deliberately —
    without it the page would render with no idea who is looking at it',
]);

it('still answers a request that asked for JSON with JSON', function (): void {
    [, $actor] = workspaceWith(WorkspaceRole::Member);

    $response = $this->actingAs($actor)->getJson('/projects/'.Str::uuid7());

    $response->assertNotFound();

    expect($response->headers->get('content-type'))->toContain('application/json');
})->with([
    'an error page would be a surprising answer to an Accept: application/json',
]);

it('answers a stranger the same way, with nothing it cannot render', function (): void {
    $this->get('/no-such-address')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Error')
            ->where('status', 404)
            ->where('auth.user', null));
})->with([
    'an error is often the answer to somebody who is not signed in, which is why the page stands
    outside the shell — the sidebar would have nothing to put in it',
]);

// 419 is left to Laravel's redirect-back handling and cannot be produced here, since tests bypass CSRF.
