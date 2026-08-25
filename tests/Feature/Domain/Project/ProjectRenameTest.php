<?php

declare(strict_types=1);

use App\Domain\Project\Actions\RenameProject;
use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Event;

it('renames a project from wherever the menu was opened', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->from(route('projects.show', $project))
        ->put(route('projects.name.update', $project), ['name' => 'Website relaunch'])
        ->assertRedirect(route('projects.show', $project));

    expect($project->fresh()?->name)->toBe('Website relaunch');
});

/*
 * The reason this endpoint exists rather than the dialog posting to `projects.update`, which
 * takes the whole settings form and reads an absent nullable field as a deliberate clearing.
 */
it('leaves everything the dialog does not show alone', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $project->forceFill([
        'slug' => 'the-old-name',
        'description' => 'The one that pays for the others',
        'start_date' => '2026-03-01',
        'due_date' => '2026-09-30',
        'visibility' => ProjectVisibility::Private->value,
    ])->save();

    $this->actingAs($actor)->put(route('projects.name.update', $project), ['name' => 'A new name']);

    $renamed = $project->fresh();

    expect($renamed?->name)->toBe('A new name')
        // Re-deriving the slug would break every link anybody saved.
        ->and($renamed?->slug)->toBe('the-old-name')
        ->and($renamed?->description)->toBe('The one that pays for the others')
        ->and($renamed?->start_date?->toDateString())->toBe('2026-03-01')
        ->and($renamed?->due_date?->toDateString())->toBe('2026-09-30')
        ->and($renamed?->visibility)->toBe(ProjectVisibility::Private);
});

it('refuses an empty name and one past the column', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $original = $project->name;

    $this->actingAs($actor)
        ->put(route('projects.name.update', $project), ['name' => ''])
        ->assertSessionHasErrors('name');

    $this->actingAs($actor)
        ->put(route('projects.name.update', $project), ['name' => str_repeat('a', 256)])
        ->assertSessionHasErrors('name');

    expect($project->fresh()?->name)->toBe($original);
});

it('refuses a reader who may open the project but not change it', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);
    $original = $project->name;

    $this->actingAs($actor)
        ->put(route('projects.name.update', $project), ['name' => 'Mine now'])
        ->assertForbidden();

    expect($project->fresh()?->name)->toBe($original);
});

it('refuses a project in another workspace', function (): void {
    [, $actor] = projectFor(WorkspaceRole::Admin, ProjectAccessLevel::Owner);
    $elsewhere = Project::factory()->in(Workspace::factory()->create())->create();
    $original = $elsewhere->name;

    $this->actingAs($actor)
        ->put(route('projects.name.update', $elsewhere), ['name' => 'Mine now'])
        ->assertNotFound();

    expect($elsewhere->fresh()?->name)->toBe($original);
});

it('announces the rename once, and says nothing when the name is the one it had', function (): void {
    Event::fake([ProjectUpdated::class]);
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    app(RenameProject::class)->handle($project, $actor, 'Renamed');

    Event::assertDispatched(ProjectUpdated::class, fn (ProjectUpdated $event): bool => $event->projectId === $project->id
        && $event->changed === ['name']);

    Event::fake([ProjectUpdated::class]);

    app(RenameProject::class)->handle($project, $actor, 'Renamed');

    Event::assertNotDispatched(ProjectUpdated::class);
});

it('refuses every caller who cannot manage the project, not only the HTTP one', function (): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Admin);

    expect(fn (): Project => app(RenameProject::class)->handle($project, $outsider, 'Mine now'))
        ->toThrow(ProjectException::class);
});
