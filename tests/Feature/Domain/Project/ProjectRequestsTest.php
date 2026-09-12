<?php

declare(strict_types=1);

use App\Domain\Project\Data\CreateProjectData;
use App\Domain\Project\Data\UpdateProjectData;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('web')->post('project-request-probe/{workspace}', function (StoreProjectRequest $request) {
        $data = CreateProjectData::fromRequest($request);

        return response()->json([
            'name' => $data->name,
            'slug' => $data->slug,
            'color' => $data->color?->value,
            'default_view' => $data->defaultView->value,
            'visibility' => $data->visibility->value,
            'start_date' => $data->startDate?->toDateString(),
            'due_date' => $data->dueDate?->toDateString(),
        ]);
    });

    // Type-hinted so implicit binding runs; a string parameter makes the request refuse every actor.
    Route::middleware('web')->put('project-request-probe/{workspace}/{project}', function (UpdateProjectRequest $request, string $workspace, Project $project) {
        $data = UpdateProjectData::fromRequest($request);

        return response()->json([
            'name' => $data->name,
            'slug' => $data->slug,
            'color' => $data->color?->value,
            'default_view' => $data->defaultView?->value,
            'visibility' => $data->visibility?->value,
        ]);
    });
});

/**
 * @return array{Workspace, User}
 */
function workspaceForProjectRequests(WorkspaceRole $role = WorkspaceRole::Member): array
{
    $workspace = Workspace::factory()->create(['slug' => 'acme']);

    return [$workspace, memberOf($workspace, $role)];
}

it('builds the data object from a valid create request', function (): void {
    [, $actor] = workspaceForProjectRequests();

    $this->actingAs($actor)
        ->postJson('project-request-probe/acme', ['name' => 'Web Redesign'])
        ->assertOk()
        ->assertJson([
            'name' => 'Web Redesign',
            'slug' => null,
            'color' => null,
            'default_view' => ProjectDefaultView::List->value,
            'visibility' => ProjectVisibility::Workspace->value,
        ]);
});

it('refuses creation to a member without the project.create capability', function (): void {
    [, $guest] = workspaceForProjectRequests(WorkspaceRole::Guest);

    $this->actingAs($guest)
        ->postJson('project-request-probe/acme', ['name' => 'Web Redesign'])
        ->assertForbidden();
});

it('rejects a create request without a name', function (): void {
    [, $actor] = workspaceForProjectRequests();

    $this->actingAs($actor)
        ->postJson('project-request-probe/acme', [])
        ->assertJsonValidationErrorFor('name');
});

it('rejects a slug that is not url safe', function (string $slug): void {
    [, $actor] = workspaceForProjectRequests();

    $this->actingAs($actor)
        ->postJson('project-request-probe/acme', ['name' => 'Web', 'slug' => $slug])
        ->assertJsonValidationErrorFor('slug');
})->with(['Web', 'web redesign', 'web_redesign', '-web', 'web-']);

it('rejects a slug another project in the same workspace already holds', function (): void {
    [$workspace, $actor] = workspaceForProjectRequests();
    Project::factory()->in($workspace)->create(['slug' => 'web']);

    $this->actingAs($actor)
        ->postJson('project-request-probe/acme', ['name' => 'Web', 'slug' => 'web'])
        ->assertJsonValidationErrorFor('slug');
});

it('accepts a slug another workspace holds, because uniqueness is per workspace', function (): void {
    [, $actor] = workspaceForProjectRequests();
    Project::factory()->create(['slug' => 'web']);

    $this->actingAs($actor)
        ->postJson('project-request-probe/acme', ['name' => 'Web', 'slug' => 'web'])
        ->assertOk()
        ->assertJson(['slug' => 'web']);
});

it('rejects a value outside an enum', function (string $field, string $value): void {
    [, $actor] = workspaceForProjectRequests();

    $this->actingAs($actor)
        ->postJson('project-request-probe/acme', ['name' => 'Web', $field => $value])
        ->assertJsonValidationErrorFor($field);
})->with([
    'visibility' => ['visibility', 'secret'],
    'default_view' => ['default_view', 'gantt'],
    'color' => ['color', 'fuchsia'],
]);

it('rejects a due date before the start date', function (): void {
    [, $actor] = workspaceForProjectRequests();

    $this->actingAs($actor)
        ->postJson('project-request-probe/acme', [
            'name' => 'Web',
            'start_date' => '2026-03-01',
            'due_date' => '2026-02-01',
        ])
        ->assertJsonValidationErrorFor('due_date');
});

it('accepts a due date with no start date at all', function (): void {
    [, $actor] = workspaceForProjectRequests();

    $this->actingAs($actor)
        ->postJson('project-request-probe/acme', ['name' => 'Web', 'due_date' => '2026-02-01'])
        ->assertOk()
        ->assertJson(['start_date' => null, 'due_date' => '2026-02-01']);
});

it('lets a project owner through the update request', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->putJson("project-request-probe/{$project->workspace->slug}/{$project->id}", [
            'id' => $project->id,
            'name' => 'Web Redesign',
            'color' => ProjectColor::Teal->value,
        ])
        ->assertOk()
        ->assertJson(['name' => 'Web Redesign', 'slug' => null, 'color' => 'teal', 'default_view' => null, 'visibility' => null]);
});

it('refuses the update request to someone who may see the project but not manage it', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);

    $this->actingAs($actor)
        ->putJson("project-request-probe/{$project->workspace->slug}/{$project->id}", [
            'id' => $project->id,
            'name' => 'Web Redesign',
        ])
        ->assertForbidden();
});

it('refuses an update carrying the id of a different project', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $other = Project::factory()->in($project->workspace)->create(['slug' => 'other']);

    $this->actingAs($actor)
        ->putJson("project-request-probe/{$project->workspace->slug}/{$project->id}", [
            'id' => $other->id,
            'name' => 'Web Redesign',
        ])
        ->assertJsonValidationErrorFor('id');
});

it('lets a project keep its own slug on update', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->putJson("project-request-probe/{$project->workspace->slug}/{$project->id}", [
            'id' => $project->id,
            'name' => 'Web Redesign',
            'slug' => $project->slug,
        ])
        ->assertOk()
        ->assertJson(['slug' => $project->slug]);
});

it('accepts on update a slug a project in another workspace holds', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    Project::factory()->create(['slug' => 'elsewhere']);

    $this->actingAs($actor)
        ->putJson("project-request-probe/{$project->workspace->slug}/{$project->id}", [
            'id' => $project->id,
            'name' => 'Web Redesign',
            'slug' => 'elsewhere',
        ])
        ->assertOk()
        ->assertJson(['slug' => 'elsewhere']);
});

it('still rejects a slug a sibling project holds', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    Project::factory()->in($project->workspace)->create(['slug' => 'taken']);

    $this->actingAs($actor)
        ->putJson("project-request-probe/{$project->workspace->slug}/{$project->id}", [
            'id' => $project->id,
            'name' => 'Web Redesign',
            'slug' => 'taken',
        ])
        ->assertJsonValidationErrorFor('slug');
});
