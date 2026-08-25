<?php

declare(strict_types=1);

use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectView;
use App\Domain\Task\Models\Task;
use Inertia\Testing\AssertableInertia;

/**
 * The URL of the files table, which is the project's own address with a view on it.
 *
 * @param  array<string, mixed>  $query
 */
function filesUrl(Project $project, array $query = []): string
{
    return route('projects.show', ['project' => $project, 'view' => ProjectView::Files->value, ...$query]);
}

it('sends the files payload and nothing of the other three views', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create(['title' => 'Write the brief']);
    attach($task, $project, $actor);

    Attachment::factory()
        ->attaching(File::factory()->in($workspace)->by($actor)->create(['original_name' => 'brief.pdf']), $task)
        ->create();

    $this->actingAs($actor)
        ->get(filesUrl($project))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('projects/Show')
            ->where('view', 'files')
            ->where('files.files.0.name', 'brief.pdf')
            ->where('files.files.0.task.title', 'Write the brief')
            ->where('files.meta.total', 1)
            ->missing('list')
            ->missing('board')
            ->missing('calendar'));
});

it('offers files as one of the views the switcher may link to', function (): void {
    [, $project, $actor] = placeableProject();

    $this->actingAs($actor)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('views', ['list', 'board', 'calendar', 'files']));
});

it('draws an empty table for a project nobody has attached anything to', function (): void {
    [, $project, $actor] = placeableProject();

    $this->actingAs($actor)
        ->get(filesUrl($project))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('files.files', [])
            ->where('files.meta.total', 0)
            ->where('files.meta.hasMore', false));
});

it('pages from the URL, so a page is a link somebody can send', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attach($task, $project, $actor);

    Attachment::factory()
        ->attaching(File::factory()->in($workspace)->by($actor)->create(), $task)
        ->create();

    $this->actingAs($actor)
        ->get(filesUrl($project, ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('files.meta.page', 2)
            ->where('files.files', []));
});

it('refuses a page that is not one', function (): void {
    [, $project, $actor] = placeableProject();

    $this->actingAs($actor)
        ->get(filesUrl($project, ['page' => 0]))
        ->assertSessionHasErrors('page');
});

it('keeps the files of a project in another workspace out of reach', function (): void {
    [, , $actor] = placeableProject();
    [$elsewhere, $theirProject] = placeableProject();
    $task = Task::factory()->in($elsewhere)->create();

    Attachment::factory()
        ->attaching(File::factory()->in($elsewhere)->create(), $task)
        ->create();

    // The binding resolves through the projects the actor can see, so a leaked id is a 404
    // rather than an empty table (ADR-0005).
    $this->actingAs($actor)
        ->get(filesUrl($theirProject))
        ->assertNotFound();
});

it('still refuses to make files a project default view', function (): void {
    // Owner access, because changing a project's settings is a manage-level act — the point of
    // the assertion is the value being refused, not who was asking.
    [, $project, $actor] = placeableProject(ProjectAccessLevel::Owner);

    $this->actingAs($actor)
        ->putJson(route('projects.update', $project), [
            'name' => $project->name,
            'default_view' => ProjectView::Files->value,
        ])
        ->assertJsonValidationErrorFor('default_view');
});

it('orders the table from the URL, so an ordering is a link somebody can send', function (): void {
    [$workspace, $project, $actor] = placeableProject();
    $task = Task::factory()->in($workspace)->create();
    attach($task, $project, $actor);

    foreach (['zebra.pdf', 'aardvark.pdf'] as $name) {
        Attachment::factory()
            ->attaching(File::factory()->in($workspace)->by($actor)->create(['original_name' => $name]), $task)
            ->create();
    }

    $this->actingAs($actor)
        ->get(filesUrl($project, ['sort' => 'name', 'direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('files.files.0.name', 'aardvark.pdf')
            ->where('files.meta.sort', 'name')
            ->where('files.meta.direction', 'asc'));
});

it('refuses an ordering it does not understand', function (): void {
    [, $project, $actor] = placeableProject();

    // A fixed vocabulary, so an unknown value is a URL built wrong rather than a stale id to be
    // kind about — the same reasoning `view` and `month` are validated by.
    $this->actingAs($actor)
        ->get(filesUrl($project, ['sort' => 'uploader']))
        ->assertSessionHasErrors('sort');
});

it('still expects a field id when the list is the view being ordered', function (): void {
    [, $project, $actor] = placeableProject();

    $this->actingAs($actor)
        ->get(route('projects.show', ['project' => $project, 'view' => 'list', 'sort' => 'name']))
        ->assertSessionHasErrors('sort');
});
