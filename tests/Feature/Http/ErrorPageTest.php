<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

it('answers an address that leads nowhere with its own page', function (): void {
    [$workspace, $actor] = workspaceWith(WorkspaceRole::Member);

    $this->actingAs($actor)
        ->get('/projects/'.Str::uuid7())
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Error')
            ->where('status', 404));
});

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
});

it('still answers a request that asked for JSON with JSON', function (): void {
    [, $actor] = workspaceWith(WorkspaceRole::Member);

    $response = $this->actingAs($actor)->getJson('/projects/'.Str::uuid7());

    $response->assertNotFound();

    expect($response->headers->get('content-type'))->toContain('application/json');
});

it('answers a stranger the same way, with nothing it cannot render', function (): void {
    $this->get('/no-such-address')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Error')
            ->where('status', 404)
            ->where('auth.user', null));
});

// 419 is left to Laravel's redirect-back handling and cannot be produced here, since tests bypass CSRF.
