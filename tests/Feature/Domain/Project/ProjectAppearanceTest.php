<?php

declare(strict_types=1);

use App\Domain\Project\Actions\UpdateProjectAppearance;
use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectIcon;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\ValueObjects\AccentColor;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

it('sets the colour and the icon from the project header', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->from(route('projects.show', $project))
        ->put(route('projects.appearance.update', $project), [
            'color' => ProjectColor::Violet->value,
            'icon' => ProjectIcon::Rocket->value,
        ])
        ->assertRedirect(route('projects.show', $project));

    expect($project->fresh()?->color?->paletteColor())->toBe(ProjectColor::Violet)
        ->and($project->fresh()?->icon)->toBe(ProjectIcon::Rocket);
});

it('clears either half when the picker sends it empty', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $project->forceFill(['color' => ProjectColor::Teal->value, 'icon' => ProjectIcon::Bug->value])->save();

    $this->actingAs($actor)
        ->put(route('projects.appearance.update', $project), ['color' => '', 'icon' => ProjectIcon::Bug->value]);

    expect($project->fresh()?->color)->toBeNull()
        ->and($project->fresh()?->icon)->toBe(ProjectIcon::Bug);

    $this->actingAs($actor)
        ->put(route('projects.appearance.update', $project), ['color' => '', 'icon' => '']);

    expect($project->fresh()?->icon)->toBeNull();
});

it('leaves everything the picker does not show alone', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $project->forceFill([
        'description' => 'The one that pays for the others',
        'start_date' => '2026-03-01',
        'due_date' => '2026-09-30',
        'visibility' => ProjectVisibility::Private->value,
    ])->save();

    $this->actingAs($actor)
        ->put(route('projects.appearance.update', $project), ['icon' => ProjectIcon::Target->value]);

    $updated = $project->fresh();

    expect($updated?->description)->toBe('The one that pays for the others')
        ->and($updated?->start_date?->toDateString())->toBe('2026-03-01')
        ->and($updated?->due_date?->toDateString())->toBe('2026-09-30')
        ->and($updated?->visibility)->toBe(ProjectVisibility::Private)
        ->and($updated?->name)->toBe($project->name);
});

it('refuses an icon outside the library and a colour outside the palette', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->put(route('projects.appearance.update', $project), ['icon' => 'skull-and-crossbones'])
        ->assertSessionHasErrors('icon');

    $this->actingAs($actor)
        ->put(route('projects.appearance.update', $project), ['color' => 'fuchsia'])
        ->assertSessionHasErrors('color');

    expect($project->fresh()?->icon)->toBeNull()
        ->and($project->fresh()?->color)->toBeNull();
});

it('refuses a reader who may open the project but not change it', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);

    $this->actingAs($actor)
        ->put(route('projects.appearance.update', $project), ['icon' => ProjectIcon::Star->value])
        ->assertForbidden();

    expect($project->fresh()?->icon)->toBeNull();
});

it('refuses a project in another workspace', function (): void {
    [, $actor] = projectFor(WorkspaceRole::Admin, ProjectAccessLevel::Owner);
    $elsewhere = Project::factory()->in(Workspace::factory()->create())->create();

    $this->actingAs($actor)
        ->put(route('projects.appearance.update', $elsewhere), ['icon' => ProjectIcon::Star->value])
        ->assertNotFound();

    expect($elsewhere->fresh()?->icon)->toBeNull();
});

it('announces the change once, naming the columns that moved', function (): void {
    Event::fake([ProjectUpdated::class]);
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    app(UpdateProjectAppearance::class)->handle($project, $actor, AccentColor::palette(ProjectColor::Sky), null);

    Event::assertDispatched(ProjectUpdated::class, fn (ProjectUpdated $event): bool => $event->projectId === $project->id
        && $event->changed === ['color']);
});

it('says nothing when the pick is the colour the project already had', function (): void {
    Event::fake([ProjectUpdated::class]);
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $project->forceFill(['color' => ProjectColor::Sky->value])->save();

    app(UpdateProjectAppearance::class)->handle($project, $actor, AccentColor::palette(ProjectColor::Sky), null);

    Event::assertNotDispatched(ProjectUpdated::class);
});

it('refuses every caller who cannot manage the project, not only the HTTP one', function (): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Admin);

    expect(fn (): Project => app(UpdateProjectAppearance::class)
        ->handle($project, $outsider, AccentColor::palette(ProjectColor::Red), null))
        ->toThrow(ProjectException::class);
});

it('rejects an icon outside the library in the database itself', function (): void {
    $project = Project::factory()->create();

    expect(fn (): int => DB::transaction(fn (): int => DB::table('projects')
        ->where('id', $project->id)
        ->update(['icon' => 'not-in-the-library'])))
        ->toThrow(QueryException::class);

    expect($project->fresh()?->icon)->toBeNull();
});

it('sends the header its icon and whether this reader may change it', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);
    $project->forceFill(['icon' => ProjectIcon::Kanban->value])->save();

    $this->actingAs($actor)
        ->get(route('projects.show', $project))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('project.icon', ProjectIcon::Kanban->value)
            ->where('project.canUpdate', false));
});

it('offers the same library on both sides', function (): void {
    $library = file_get_contents(resource_path('js/lib/projectIcon.ts'));

    expect($library)->toBeString();

    preg_match_all("/^    '?([a-z-]+)'?: [A-Z]/m", (string) $library, $matches);

    expect($matches[1])->toEqualCanonicalizing(array_column(ProjectIcon::cases(), 'value'));
});
