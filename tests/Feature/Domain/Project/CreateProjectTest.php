<?php

declare(strict_types=1);

use App\Domain\Project\Actions\CreateProject;
use App\Domain\Project\Data\CreateProjectData;
use App\Domain\Project\Events\ProjectCreated;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Section\Data\CreateSectionData;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Shared\ValueObjects\AccentColor;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function createProject(Workspace $workspace, User $creator, ?CreateProjectData $data = null): Project
{
    return app(CreateProject::class)->handle($workspace, $creator, $data ?? new CreateProjectData(name: 'Web Redesign'));
}

it('creates the project and makes its creator an owner of it', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);

    $project = createProject($workspace, $creator);

    expect($project->workspace_id)->toBe($workspace->id)
        ->and($project->owner_id)->toBe($creator->id)
        ->and($project->created_by)->toBe($creator->id)
        ->and($project->slug)->toBe('web-redesign')
        ->and($project->memberFor($creator)?->access_level)->toBe(ProjectAccessLevel::Owner);
});

it('stores the accent colour by name and reads it back as the enum', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);

    $project = createProject($workspace, $creator, new CreateProjectData(name: 'Web Redesign', color: AccentColor::palette(ProjectColor::Violet)));

    expect(DB::table('projects')->where('id', $project->id)->value('color'))->toBe('violet')
        ->and($project->fresh()?->color?->paletteColor())->toBe(ProjectColor::Violet);
});

it('opens the project with one placeholder column', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);

    $project = createProject($workspace, $creator);

    expect($project->sections()->pluck('name')->all())->toBe(['Untitled section'])
        ->and(Section::DEFAULT_NAMES)->toBe(['Untitled section'])
        ->and($project->sections()->pluck('position')->all())
        ->toBe(SparsePosition::spread(count(Section::DEFAULT_NAMES)))
        // Slate, the same colour a column added by hand starts with — the project's first column
        // is not a different kind of column.
        ->and($project->sections()->first()?->color?->paletteColor())->toBe(CreateSectionData::DEFAULT_COLOR);
});

it('rolls the default sections back with the project', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);
    Project::factory()->in($workspace)->create(['slug' => 'taken']);

    expect(fn (): Project => DB::transaction(fn (): Project => createProject(
        $workspace,
        $creator,
        new CreateProjectData(name: 'Taken', slug: 'taken'),
    )))->toThrow(QueryException::class);

    expect(Section::query()->count())->toBe(0);
});

it('refuses an actor without the project.create capability', function (WorkspaceRole $role): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, $role);

    expect(fn (): Project => createProject($workspace, $actor))
        ->toThrow(ProjectException::class, 'permission to create projects');

    expect(Project::query()->count())->toBe(0);
})->with(['guest' => WorkspaceRole::Guest]);

it('refuses an actor whose workspace membership is not active', function (): void {
    $workspace = Workspace::factory()->create();
    $revoked = memberOf($workspace, WorkspaceRole::Owner, WorkspaceMembershipStatus::Revoked);

    expect(fn (): Project => createProject($workspace, $revoked))->toThrow(ProjectException::class);

    expect(Project::query()->count())->toBe(0);
});

it('refuses someone from another workspace', function (): void {
    $theirs = Workspace::factory()->create();
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);

    expect(fn (): Project => createProject($theirs, $outsider))->toThrow(ProjectException::class);
});

it('resolves a slug collision within the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);

    $first = createProject($workspace, $creator);
    $second = createProject($workspace, $creator);

    expect($first->slug)->toBe('web-redesign')
        ->and($second->slug)->toBe('web-redesign-2');
});

it('lets two workspaces hold the same project slug', function (): void {
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();

    $ours = createProject($mine, memberOf($mine, WorkspaceRole::Member));
    $others = createProject($theirs, memberOf($theirs, WorkspaceRole::Member));

    expect($ours->slug)->toBe($others->slug);
});

it('rolls the membership back when the project insert fails', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);
    Project::factory()->in($workspace)->create(['slug' => 'taken']);

    expect(fn (): Project => DB::transaction(fn (): Project => createProject(
        $workspace,
        $creator,
        new CreateProjectData(name: 'Taken', slug: 'taken'),
    )))->toThrow(QueryException::class);

    expect(ProjectMembership::query()->count())->toBe(0)
        ->and(Project::query()->where('name', 'Taken')->count())->toBe(0);
});

it('honours the caller-supplied attributes', function (): void {
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);

    $project = createProject($workspace, $creator, new CreateProjectData(
        name: 'Client Work',
        slug: 'client',
        description: 'Everything for the client',
        visibility: ProjectVisibility::Private,
    ));

    expect($project->slug)->toBe('client')
        ->and($project->description)->toBe('Everything for the client')
        ->and($project->visibility)->toBe(ProjectVisibility::Private);
});

it('announces the project after the transaction', function (): void {
    Event::fake();
    $workspace = Workspace::factory()->create();
    $creator = memberOf($workspace, WorkspaceRole::Member);

    $project = createProject($workspace, $creator);

    Event::assertDispatched(ProjectCreated::class, fn (ProjectCreated $event): bool => $event->projectId === $project->id
        && $event->workspaceId === $workspace->id
        && $event->createdById === $creator->id);
});
