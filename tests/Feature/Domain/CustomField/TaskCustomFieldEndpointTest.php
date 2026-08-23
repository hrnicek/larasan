<?php

declare(strict_types=1);

use App\Domain\CustomField\Actions\AttachFieldToProject;
use App\Domain\CustomField\Actions\DefineCustomField;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\TaskCustomFieldValue;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Inertia\Testing\AssertableInertia;

it('sets a value over HTTP', function (): void {
    [$task, $field, $actor] = fieldOnATask(CustomFieldType::Number);

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => '12.5'])
        ->assertRedirect(route('tasks.show', $task));

    expect((float) $task->customFieldValues()->sole()->value_number)->toBe(12.5);
});

it('clears a value with the same request and nothing in it', function (): void {
    [$task, $field, $actor] = fieldOnATask();
    $this->actingAs($actor)->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => 'Two days']);

    // Clearing is the same request with nothing in it rather than a DELETE nobody would think
    // to send.
    $this->actingAs($actor)
        ->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => null])
        ->assertRedirect();

    expect(TaskCustomFieldValue::query()->count())->toBe(0);
});

it('refuses a value the field s type cannot hold', function (): void {
    [$task, $field, $actor] = fieldOnATask(CustomFieldType::Number);

    // The rules come from the field's own type, so "what may be written here" is stated once.
    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => 'not a number'])
        ->assertSessionHasErrors('value');

    expect(TaskCustomFieldValue::query()->count())->toBe(0);
});

it('refuses a date that is not one', function (): void {
    [$task, $field, $actor] = fieldOnATask(CustomFieldType::Date);

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => 'someday'])
        ->assertSessionHasErrors('value');
});

it('refuses somebody who may not edit the task', function (): void {
    [$task, $field] = fieldOnATask();
    $guest = memberOf($task->workspace, WorkspaceRole::Guest);

    $this->actingAs($guest)
        ->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => 'Two days'])
        ->assertForbidden();
});

it('hides a field from another workspace behind a 404', function (): void {
    [$task, , $actor] = fieldOnATask();
    $elsewhere = CustomField::factory()->create();

    $this->actingAs($actor)
        ->put(route('tasks.custom-fields.update', [$task, $elsewhere]), ['value' => 'Two days'])
        ->assertNotFound();
});

it('refuses a field this task s projects do not show', function (): void {
    [$task, , $actor] = fieldOnATask();
    $unattached = app(DefineCustomField::class)->handle($task->workspace, $actor, 'Risk', CustomFieldType::Text);

    // The refusal is the Action's, rendered as a domain refusal rather than a 500.
    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('tasks.custom-fields.update', [$task, $unattached]), ['value' => 'High'])
        ->assertSessionHas('errors');
});

it('sends the fields and the answers to the task detail', function (): void {
    [$task, $field, $actor, $project] = fieldOnATask(CustomFieldType::Select, ['Draft', 'Done']);
    $option = $field->options()->first();

    $this->actingAs($actor)->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => $option?->id]);

    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('customFields', 1)
            ->where('customFields.0.type', 'select')
            ->has('customFields.0.options', 2)
            ->where('customFields.0.value', $option?->id));

    expect($project->customFields()->count())->toBe(1);
});

it('names a field only once even when two of the task s projects show it', function (): void {
    [$task, $field, $actor] = fieldOnATask();
    $second = Project::factory()->in($task->workspace)->create();
    ProjectMembership::factory()->in($second)->forUser($actor)
        ->withAccess(ProjectAccessLevel::Editor)->create();
    app(AttachFieldToProject::class)->handle($second, $field, $actor);
    TaskProjectMembership::factory()->placing($task, $second)->create();

    $this->actingAs($actor)
        ->get(route('tasks.show', $task))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->has('customFields', 1));
});

it('turns away everybody who is not signed in', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $field = CustomField::factory()->in($workspace)->create();

    $this->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => 'x'])
        ->assertRedirect(route('login'));
});
