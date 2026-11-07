<?php

declare(strict_types=1);

use App\Domain\Project\Actions\SetProjectDefaultView;
use App\Domain\Project\Events\ProjectUpdated;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

it('opens the project on the view chosen from its tabs', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->from(route('projects.show', ['project' => $project, 'view' => 'board']))
        ->put(route('projects.default-view.update', $project), ['default_view' => 'board'])
        ->assertRedirect(route('projects.show', ['project' => $project, 'view' => 'board']));

    expect($project->fresh()?->default_view)->toBe(ProjectDefaultView::Board);

    $this->actingAs($actor)
        ->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('view', 'board')
            ->where('project.defaultView', 'board'));
});

it('leaves everything else about the project alone', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $project->forceFill([
        'description' => 'The one that pays for the others',
        'due_date' => '2026-09-30',
        'visibility' => ProjectVisibility::Private->value,
    ])->save();

    $this->actingAs($actor)->put(route('projects.default-view.update', $project), ['default_view' => 'calendar']);

    $updated = $project->fresh();

    expect($updated?->default_view)->toBe(ProjectDefaultView::Calendar)
        ->and($updated?->description)->toBe('The one that pays for the others')
        ->and($updated?->due_date?->toDateString())->toBe('2026-09-30')
        ->and($updated?->visibility)->toBe(ProjectVisibility::Private);
});

it('refuses a view a project cannot open on', function (string $view): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->put(route('projects.default-view.update', $project), ['default_view' => $view])
        ->assertSessionHasErrors('default_view');

    expect($project->fresh()?->default_view)->toBe(ProjectDefaultView::List);
})->with(['files', 'pages', '']);

it('refuses a reader who may open the project but not change it', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Viewer);

    $this->actingAs($actor)
        ->put(route('projects.default-view.update', $project), ['default_view' => 'board'])
        ->assertForbidden();

    expect($project->fresh()?->default_view)->toBe(ProjectDefaultView::List);
});

it('refuses a project in another workspace', function (): void {
    [, $actor] = projectFor(WorkspaceRole::Admin, ProjectAccessLevel::Owner);
    $elsewhere = Project::factory()->in(Workspace::factory()->create())->create();

    $this->actingAs($actor)
        ->put(route('projects.default-view.update', $elsewhere), ['default_view' => 'board'])
        ->assertNotFound();

    expect($elsewhere->fresh()?->default_view)->toBe(ProjectDefaultView::List);
});

it('announces the change once, and says nothing when the view is the one it had', function (): void {
    Event::fake([ProjectUpdated::class]);
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);

    app(SetProjectDefaultView::class)->handle($project, $actor, ProjectDefaultView::Board);

    Event::assertDispatched(ProjectUpdated::class, fn (ProjectUpdated $event): bool => $event->projectId === $project->id
        && $event->changed === ['default_view']);

    Event::fake([ProjectUpdated::class]);

    app(SetProjectDefaultView::class)->handle($project, $actor, ProjectDefaultView::Board);

    Event::assertNotDispatched(ProjectUpdated::class);
});

it('refuses every caller who cannot manage the project, not only the HTTP one', function (): void {
    [$project] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Owner);
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Admin);

    expect(fn (): Project => app(SetProjectDefaultView::class)->handle($project, $outsider, ProjectDefaultView::Board))
        ->toThrow(ProjectException::class);
});
