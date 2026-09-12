<?php

declare(strict_types=1);

use App\Domain\CustomField\Actions\AttachFieldToProject;
use App\Domain\CustomField\Actions\DefineCustomField;
use App\Domain\CustomField\Models\ProjectCustomField;
use App\Domain\CustomField\Models\TaskCustomFieldValue;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\HandleInertiaRequests;
use Inertia\Testing\AssertableInertia;

it('offers the project what it shows and what the workspace has left', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();

    $shown = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Number);
    app(DefineCustomField::class)->handle($workspace, $owner, 'Client', CustomFieldType::Text);
    app(AttachFieldToProject::class)->handle($project, $shown, $owner);

    $this->actingAs($owner)
        ->get(route('projects.edit', $project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/Settings')
            ->has('customFields.attached', 1)
            ->where('customFields.attached.0.name', 'Estimate')
            ->where('customFields.attached.0.type', 'number')
            ->has('customFields.available', 1)
            ->where('customFields.available.0.name', 'Client')
            ->where('can.manageFields', true),
        );
});

it('never offers a project a field from another workspace', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    [$elsewhere, $stranger] = workspaceWith(WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();

    app(DefineCustomField::class)->handle($elsewhere, $stranger, 'Their field', CustomFieldType::Text);

    $this->actingAs($owner)
        ->get(route('projects.edit', $project))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('customFields.available', 0));
});

it('attaches a field to a project', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();
    $field = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Number);

    $this->actingAs($owner)
        ->post(route('projects.custom-fields.store', $project), ['field' => $field->id])
        ->assertRedirect();

    expect($project->customFields()->pluck('custom_fields.id')->all())->toBe([$field->id]);
});

it('treats attaching twice as the same column', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();
    $field = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Number);

    $this->actingAs($owner)->post(route('projects.custom-fields.store', $project), ['field' => $field->id]);
    $this->actingAs($owner)
        ->post(route('projects.custom-fields.store', $project), ['field' => $field->id])
        ->assertRedirect();

    expect(ProjectCustomField::query()->count())->toBe(1);
});

it('refuses a field from another workspace', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    [$elsewhere, $stranger] = workspaceWith(WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();
    $theirs = app(DefineCustomField::class)->handle($elsewhere, $stranger, 'Their field', CustomFieldType::Text);

    $this->actingAs($owner)
        ->from(route('projects.edit', $project))
        ->post(route('projects.custom-fields.store', $project), ['field' => $theirs->id])
        ->assertSessionHasErrors('field');

    expect(ProjectCustomField::query()->count())->toBe(0);
});

it('detaches a field and keeps every answer', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();
    $field = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);
    app(AttachFieldToProject::class)->handle($project, $field, $owner);

    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $this->actingAs($owner)->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => 'Two days']);

    $this->actingAs($owner)
        ->delete(route('projects.custom-fields.destroy', [$project, $field]))
        ->assertRedirect();

    expect(ProjectCustomField::query()->count())->toBe(0)
        ->and(TaskCustomFieldValue::query()->count())->toBe(1)
        ->and($task->customFieldValues()->sole()->value_text)->toBe('Two days');
});

it('refuses a project editor who does not manage the workspace fields', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $editor = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->create();
    ProjectMembership::factory()->in($project)->forUser($editor)->withAccess(ProjectAccessLevel::Editor)->create();

    $field = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);
    app(AttachFieldToProject::class)->handle($project, $field, $owner);
    $other = app(DefineCustomField::class)->handle($workspace, $owner, 'Client', CustomFieldType::Text);

    // Attaching fields needs custom_field.manage, not project update access. See ADR-0010.
    $this->actingAs($editor)
        ->post(route('projects.custom-fields.store', $project), ['field' => $other->id])
        ->assertForbidden();

    $this->actingAs($editor)
        ->delete(route('projects.custom-fields.destroy', [$project, $field]))
        ->assertForbidden();

    $this->actingAs($editor)
        ->get(route('projects.edit', $project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('customFields.attached', 1)
            ->where('can.manageFields', false),
        );

    expect(ProjectCustomField::query()->count())->toBe(1);
});

it('answers a project in another workspace with a 404', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    [$elsewhere] = workspaceWith(WorkspaceRole::Owner);
    $theirs = Project::factory()->in($elsewhere)->create();
    $field = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);

    $this->actingAs($owner)
        ->post(route('projects.custom-fields.store', $theirs), ['field' => $field->id])
        ->assertNotFound();
});

it('answers a field id that is not one with a validation error', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();

    $this->actingAs($owner)
        ->from(route('projects.edit', $project))
        ->post(route('projects.custom-fields.store', $project), ['field' => 'not-a-uuid'])
        ->assertSessionHasErrors('field');
});

it('turns away a workspace that is not the actor s', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = memberOf(Workspace::factory()->create(), WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();

    $this->actingAs($outsider)
        ->post(route('projects.custom-fields.store', $project), ['field' => fake()->uuid()])
        ->assertNotFound();
});

it('does not send the drawer to somebody who only opened the project', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();
    app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);

    $this->actingAs($owner)
        ->get(route('projects.show', $project))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/Show')
            ->where('project.canCustomize', true)
            ->missing('customize'),
        );
});

it('answers the drawer when it asks', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $project = Project::factory()->in($workspace)->create();
    $shown = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);
    app(DefineCustomField::class)->handle($workspace, $owner, 'Client', CustomFieldType::Text);
    app(AttachFieldToProject::class)->handle($project, $shown, $owner);

    // Skipped so the partial request is not rejected for an unknown asset version.
    $this->actingAs($owner)
        ->withoutMiddleware(HandleInertiaRequests::class)
        ->get(route('projects.show', $project), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'projects/Show',
            'X-Inertia-Partial-Data' => 'customize',
        ])
        ->assertOk()
        ->assertJsonPath('props.customize.fields.attached.0.name', 'Estimate')
        ->assertJsonPath('props.customize.fields.available.0.name', 'Client');
});

it('does not draw the drawer for somebody who may not customize', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $member = memberOf($workspace, WorkspaceRole::Member);
    $project = Project::factory()->in($workspace)->create();
    ProjectMembership::factory()->in($project)->forUser($member)->withAccess(ProjectAccessLevel::Editor)->create();

    $this->actingAs($member)
        ->get(route('projects.show', $project))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('project.canCustomize', false));
});
