<?php

declare(strict_types=1);

use App\Domain\Project\Actions\ArchiveProject;
use App\Domain\Project\Actions\UpdateProject;
use App\Domain\Project\Data\UpdateProjectData;
use App\Domain\Project\Events\ProjectArchived;
use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * @return array{Project, User}
 */
function projectManagedBy(
    ProjectAccessLevel $access = ProjectAccessLevel::Owner,
    WorkspaceRole $role = WorkspaceRole::Member,
): array {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);
    $project = Project::factory()->in($workspace)->create(['name' => 'Web', 'slug' => 'web']);

    ProjectMembership::factory()->in($project)->forUser($actor)->withAccess($access)->create();

    return [$project, $actor];
}

it('updates the mutable attributes and leaves the slug alone', function (): void {
    [$project, $actor] = projectManagedBy();

    app(UpdateProject::class)->handle($project, $actor, new UpdateProjectData(
        name: 'Web Redesign',
        visibility: ProjectVisibility::Private,
    ));

    expect($project->fresh()?->name)->toBe('Web Redesign')
        ->and($project->fresh()?->slug)->toBe('web')
        ->and($project->fresh()?->visibility)->toBe(ProjectVisibility::Private);
});

it('clears a nullable field the caller emptied, and keeps the ones null cannot describe', function (): void {
    [$project, $actor] = projectManagedBy();
    $project->forceFill([
        'description' => 'Old',
        'color' => ProjectColor::Teal->value,
        'due_date' => '2026-03-01',
        'visibility' => ProjectVisibility::Private->value,
    ])->save();

    app(UpdateProject::class)->handle($project, $actor, new UpdateProjectData(name: 'Web'));

    $updated = $project->fresh();

    expect($updated?->description)->toBeNull()
        ->and($updated?->color)->toBeNull()
        ->and($updated?->due_date)->toBeNull()
        ->and($updated?->visibility)->toBe(ProjectVisibility::Private)
        ->and($updated?->slug)->toBe('web');
});

it('never moves a project between workspaces or hands it to someone else', function (): void {
    [$project, $actor] = projectManagedBy();
    $before = $project->only(['workspace_id', 'owner_id']);

    app(UpdateProject::class)->handle($project, $actor, new UpdateProjectData(name: 'Renamed'));

    expect($project->fresh()?->only(['workspace_id', 'owner_id']))->toBe($before);
});

it('rejects a slug another project in the same workspace holds', function (): void {
    [$project, $actor] = projectManagedBy();
    Project::factory()->in($project->workspace)->create(['slug' => 'taken']);

    expect(fn (): Project => DB::transaction(fn (): Project => app(UpdateProject::class)
        ->handle($project, $actor, new UpdateProjectData(name: 'Web', slug: 'taken'))))
        ->toThrow(QueryException::class);

    expect($project->fresh()?->slug)->toBe('web');
});

it('announces only what changed, and stays quiet on a no-op', function (): void {
    Event::fake();
    [$project, $actor] = projectManagedBy();

    app(UpdateProject::class)->handle($project, $actor, new UpdateProjectData(name: 'Web'));
    Event::assertNotDispatched(ProjectUpdated::class);

    app(UpdateProject::class)->handle($project, $actor, new UpdateProjectData(name: 'Web Two'));
    Event::assertDispatched(ProjectUpdated::class, fn (ProjectUpdated $event): bool => $event->changed === ['name']);
});

it('refuses management without both halves of the answer', function (ProjectAccessLevel $access, WorkspaceRole $role): void {
    [$project, $actor] = projectManagedBy($access, $role);

    expect(fn (): Project => app(UpdateProject::class)->handle($project, $actor, new UpdateProjectData(name: 'Renamed')))
        ->toThrow(ProjectException::class, 'permission to change');

    expect($project->fresh()?->name)->toBe('Web');
})->with([
    'editor in the project' => [ProjectAccessLevel::Editor, WorkspaceRole::Member],
    'viewer in the project' => [ProjectAccessLevel::Viewer, WorkspaceRole::Member],
    'project owner but a workspace guest' => [ProjectAccessLevel::Owner, WorkspaceRole::Guest],
]);

it('refuses someone with no project membership at all, however capable in the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $admin = memberOf($workspace, WorkspaceRole::Admin);
    $project = Project::factory()->in($workspace)->create(['name' => 'Web']);

    expect(fn (): Project => app(UpdateProject::class)->handle($project, $admin, new UpdateProjectData(name: 'Renamed')))
        ->toThrow(ProjectException::class);

    expect($project->fresh()?->name)->toBe('Web');
});

it('archives and restores without touching anything else', function (): void {
    Event::fake();
    [$project, $actor] = projectManagedBy();
    ProjectMembership::factory()->in($project)->create();

    app(ArchiveProject::class)->archive($project, $actor);

    expect($project->fresh()?->isArchived())->toBeTrue()
        ->and(Project::query()->active()->count())->toBe(0)
        ->and(Project::query()->whereKey($project->id)->exists())->toBeTrue()
        ->and($project->memberships()->count())->toBe(2);

    app(ArchiveProject::class)->restore($project, $actor);

    expect($project->fresh()?->isArchived())->toBeFalse()
        ->and(Project::query()->active()->count())->toBe(1);

    Event::assertDispatchedTimes(ProjectArchived::class, 2);
});

it('stays quiet when archiving something already archived', function (): void {
    Event::fake();
    [$project, $actor] = projectManagedBy();

    app(ArchiveProject::class)->archive($project, $actor);
    app(ArchiveProject::class)->archive($project->refresh(), $actor);

    Event::assertDispatchedTimes(ProjectArchived::class, 1);
});

it('refuses archiving to anyone who may not manage the project', function (): void {
    [$project, $actor] = projectManagedBy(ProjectAccessLevel::Editor);

    expect(fn (): Project => app(ArchiveProject::class)->archive($project, $actor))
        ->toThrow(ProjectException::class);

    expect($project->fresh()?->isArchived())->toBeFalse();
});
