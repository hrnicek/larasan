<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Data\CreateWorkspaceData;
use App\Domain\Workspace\Data\UpdateWorkspaceData;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Requests\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceRequest;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('web')->post('workspace-request-probe', function (StoreWorkspaceRequest $request) {
        $data = CreateWorkspaceData::fromRequest($request);

        return response()->json(['name' => $data->name, 'slug' => $data->slug, 'timezone' => $data->timezone]);
    });

    Route::middleware('web')->put('workspace-request-probe/{workspace}', function (UpdateWorkspaceRequest $request) {
        $data = UpdateWorkspaceData::fromRequest($request);

        return response()->json(['name' => $data->name, 'slug' => $data->slug, 'timezone' => $data->timezone]);
    });
});

it('builds the data object from a valid create request', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->postJson('workspace-request-probe', ['name' => 'Acme']);

    $response->assertOk()->assertJson(['name' => 'Acme', 'slug' => null, 'timezone' => 'UTC']);
});

it('rejects a create request without a name', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson('workspace-request-probe', [])
        ->assertJsonValidationErrorFor('name');
});

it('rejects a slug that is not url safe', function (string $slug): void {
    $this->actingAs(User::factory()->create())
        ->postJson('workspace-request-probe', ['name' => 'Acme', 'slug' => $slug])
        ->assertJsonValidationErrorFor('slug');
})->with(['Acme', 'acme industries', 'acme_industries', '-acme', 'acme-']);

it('rejects a slug another workspace already holds', function (): void {
    Workspace::factory()->create(['slug' => 'acme']);

    $this->actingAs(User::factory()->create())
        ->postJson('workspace-request-probe', ['name' => 'Acme', 'slug' => 'acme'])
        ->assertJsonValidationErrorFor('slug');
});

it('rejects a timezone that does not exist', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson('workspace-request-probe', ['name' => 'Acme', 'timezone' => 'Mars/Olympus'])
        ->assertJsonValidationErrorFor('timezone');
});

it('lets an admin through the update request', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $admin = memberOf($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->putJson('workspace-request-probe/acme', ['id' => $workspace->id, 'name' => 'Acme Industries'])
        ->assertOk()
        ->assertJson(['name' => 'Acme Industries', 'slug' => null, 'timezone' => null]);
});

it('refuses the update request to a member who cannot manage the workspace', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $member = memberOf($workspace, WorkspaceRole::Member);

    $this->actingAs($member)
        ->putJson('workspace-request-probe/acme', ['id' => $workspace->id, 'name' => 'Acme Industries'])
        ->assertForbidden();
});

it('lets a workspace keep its own slug on update', function (): void {
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $admin = memberOf($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->putJson('workspace-request-probe/acme', ['id' => $workspace->id, 'name' => 'Acme', 'slug' => 'acme'])
        ->assertOk()
        ->assertJson(['slug' => 'acme']);
});

it('still rejects a slug that belongs to a different workspace', function (): void {
    Workspace::factory()->create(['slug' => 'taken']);
    $workspace = Workspace::factory()->create(['slug' => 'acme']);
    $admin = memberOf($workspace, WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->putJson('workspace-request-probe/acme', ['id' => $workspace->id, 'name' => 'Acme', 'slug' => 'taken'])
        ->assertJsonValidationErrorFor('slug');
});
