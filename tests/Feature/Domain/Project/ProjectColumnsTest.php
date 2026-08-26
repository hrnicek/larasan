<?php

declare(strict_types=1);

use App\Domain\CustomField\Actions\AttachFieldToProject;
use App\Domain\CustomField\Actions\DefineCustomField;
use App\Domain\CustomField\Actions\DetachFieldFromProject;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\Project\Actions\ReorderProjectColumns;
use App\Domain\Project\Data\ListColumns;
use App\Domain\Project\Exceptions\ProjectException;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * The order the list draws its columns in. Stored as a wish rather than a fact: a field named in
 * it may since have been detached, and one attached afterwards is named nowhere.
 */

/**
 * A project with two fields on it, and the owner who may reorder them.
 *
 * @return array{Project, User, string, string}
 */
function projectWithColumns(): array
{
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();

    $estimate = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Number);
    $client = app(DefineCustomField::class)->handle($workspace, $owner, 'Client', CustomFieldType::Text);

    app(AttachFieldToProject::class)->handle($project, $estimate, $owner);
    app(AttachFieldToProject::class)->handle($project, $client, $owner);

    return [$project, $owner, $estimate->id, $client->id];
}

it('draws the order it always drew until somebody changes it', function (): void {
    [$project, , $estimate, $client] = projectWithColumns();

    // Null is a project nobody has reordered, not a project with no columns — which is what keeps
    // this feature from changing a single existing screen.
    expect($project->list_columns)->toBeNull()
        ->and(ListColumns::for($project, $project->customFields))
        ->toBe([$estimate, $client, 'assignee', 'due', 'priority']);
});

it('stores an order and draws it', function (): void {
    [$project, $owner, $estimate, $client] = projectWithColumns();

    $this->actingAs($owner)
        ->put(route('projects.columns.update', $project), [
            'columns' => ['due', $client, 'assignee', $estimate, 'priority'],
        ])
        ->assertRedirect();

    expect(ListColumns::for($project->fresh() ?? $project, $project->customFields))
        ->toBe(['due', $client, 'assignee', $estimate, 'priority']);
});

it('stores nothing when the order is put back the way it was', function (): void {
    [$project, $owner, $estimate, $client] = projectWithColumns();

    $this->actingAs($owner)->put(route('projects.columns.update', $project), [
        'columns' => ['due', $client, 'assignee', $estimate, 'priority'],
    ]);

    $this->actingAs($owner)->put(route('projects.columns.update', $project), [
        'columns' => [$estimate, $client, 'assignee', 'due', 'priority'],
    ]);

    /*
     * A project put back the way it was should read as one nobody has reordered — otherwise a
     * field attached later lands behind an order that happens to name everything, rather than at
     * the end where it belongs.
     */
    expect($project->fresh()?->list_columns)->toBeNull();
});

it('puts a field attached later at the end, and lets a detached one go', function (): void {
    [$project, $owner, $estimate, $client] = projectWithColumns();
    $workspace = $project->workspace;

    $this->actingAs($owner)->put(route('projects.columns.update', $project), [
        'columns' => [$client, 'priority', $estimate, 'assignee', 'due'],
    ]);

    $stage = app(DefineCustomField::class)->handle($workspace, $owner, 'Stage', CustomFieldType::Text);
    app(AttachFieldToProject::class)->handle($project, $stage, $owner);

    $project->refresh()->load('customFields');

    expect(ListColumns::for($project, $project->customFields))
        ->toBe([$client, 'priority', $estimate, 'assignee', 'due', $stage->id]);

    app(DetachFieldFromProject::class)->handle($project, CustomField::query()->findOrFail($estimate), $owner);
    $project->refresh()->load('customFields');

    // The stored order still names it; it is dropped on the way out rather than drawn as an empty
    // column, and the rest of the order is untouched.
    expect(ListColumns::for($project, $project->customFields))
        ->toBe([$client, 'priority', 'assignee', 'due', $stage->id]);
});

it('ignores a key the project cannot draw', function (): void {
    [$project, $owner, $estimate, $client] = projectWithColumns();
    [$elsewhere, $stranger] = workspaceWith(WorkspaceRole::Owner);
    $theirs = app(DefineCustomField::class)->handle($elsewhere, $stranger, 'Theirs', CustomFieldType::Text);

    $this->actingAs($owner)
        ->put(route('projects.columns.update', $project), [
            'columns' => ['due', $theirs->id, $client, 'assignee', $estimate, 'priority', 'nonsense'],
        ])
        ->assertRedirect();

    expect($project->fresh()?->list_columns)->toBe(['due', $client, 'assignee', $estimate, 'priority']);
});

it('sends the columns to the list, header and rows alike', function (): void {
    [$project, $owner, $estimate, $client] = projectWithColumns();

    $this->actingAs($owner)->put(route('projects.columns.update', $project), [
        'columns' => ['due', $client, 'assignee', $estimate, 'priority'],
    ]);

    $this->actingAs($owner)
        ->get(route('projects.show', [$project, 'view' => 'list']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('list.columns.0', ['key' => 'due', 'kind' => 'due', 'label' => 'Due', 'type' => null])
            ->where('list.columns.1.key', $client)
            ->where('list.columns.1.kind', 'field')
            ->where('list.columns.1.label', 'Client')
            ->where('list.columns.1.type', 'text')
            ->where('list.columns.4.key', 'priority'),
        );
});

it('sends the same order to the drawer that reorders it', function (): void {
    [$project, $owner, $estimate, $client] = projectWithColumns();

    $this->actingAs($owner)->put(route('projects.columns.update', $project), [
        'columns' => ['priority', $estimate, 'assignee', $client, 'due'],
    ]);

    $response = $this->actingAs($owner)
        ->withoutMiddleware(HandleInertiaRequests::class)
        ->get(route('projects.show', $project), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'projects/Show',
            'X-Inertia-Partial-Data' => 'customize',
        ])
        ->assertOk();

    // The drawer opens over the board and the calendar too, so it carries the order itself rather
    // than reading the list view's.
    $response->assertJsonPath('props.customize.columns.0.key', 'priority')
        ->assertJsonPath('props.customize.columns.1.key', $estimate)
        ->assertJsonPath('props.customize.columns.4.key', 'due');
});

it('refuses somebody without custom_field.manage', function (): void {
    [$project, $owner, $estimate, $client] = projectWithColumns();
    $member = memberOf($project->workspace, WorkspaceRole::Member);

    // The same permission attaching a field asks for: it is one drawer and one kind of decision
    // about everybody's board (ADR-0010).
    $this->actingAs($member)
        ->put(route('projects.columns.update', $project), ['columns' => ['due', $client, 'assignee', $estimate, 'priority']])
        ->assertForbidden();

    expect($project->fresh()?->list_columns)->toBeNull();

    expect(fn () => app(ReorderProjectColumns::class)->handle($project, $member, ['due']))
        ->toThrow(ProjectException::class);
});

it('answers a project in another workspace with a 404', function (): void {
    [, $owner] = projectWithColumns();
    [$elsewhere] = workspaceWith(WorkspaceRole::Owner);
    $theirs = Project::factory()->in($elsewhere)->create();

    $this->actingAs($owner)
        ->put(route('projects.columns.update', $theirs), ['columns' => ['due']])
        ->assertNotFound();
});

it('refuses a payload that is not a list of keys', function (): void {
    [$project, $owner] = projectWithColumns();

    $this->actingAs($owner)
        ->from(route('projects.edit', $project))
        ->put(route('projects.columns.update', $project), ['columns' => [['due']]])
        ->assertSessionHasErrors('columns.0');

    expect($project->fresh()?->list_columns)->toBeNull();
});

it('leaves the lists that belong to no project alone', function (): void {
    [$workspace, $actor] = workspaceWith(WorkspaceRole::Owner);
    Workspace::query()->whereKey($workspace->id)->exists();

    // My Tasks has no project and therefore nothing to reorder; its rows draw the default.
    $this->actingAs($actor)
        ->get(route('my-tasks.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('columns'));
});
