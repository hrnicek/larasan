<?php

declare(strict_types=1);

use App\Domain\CustomField\Actions\AttachFieldToProject;
use App\Domain\CustomField\Actions\DefineCustomField;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\CustomFieldOption;
use App\Domain\CustomField\Models\TaskCustomFieldValue;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use Inertia\Testing\AssertableInertia;

/*
 * The transport for the five Actions Phase 150 left without one. `custom_field.manage` is an
 * owner's and an admin's, so a member reads this screen and writes nothing on it.
 */

it('renders the workspace fields with their choices and what they would cost to delete', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);

    $size = app(DefineCustomField::class)->handle(
        $workspace,
        $owner,
        'Size',
        CustomFieldType::Select,
        ['Small', 'Large'],
    );

    $project = Project::factory()->in($workspace)->create();
    app(AttachFieldToProject::class)->handle($project, $size, $owner);

    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $this->actingAs($owner)->put(route('tasks.custom-fields.update', [$task, $size]), [
        'value' => $size->options()->first()?->id,
    ]);

    $this->actingAs($owner)
        ->get(route('custom-fields.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Fields')
            ->where('fields.0.name', 'Size')
            ->where('fields.0.type', 'select')
            ->where('fields.0.options.0.label', 'Small')
            ->where('fields.0.options.1.label', 'Large')
            ->where('fields.0.projectCount', 1)
            ->where('fields.0.valueCount', 1)
            ->where('can.manage', true)
            ->where('types', array_column(CustomFieldType::cases(), 'value')),
        );
});

it('shows a member the list and none of the controls', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $member = memberOf($workspace, WorkspaceRole::Member);
    app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);

    $this->actingAs($member)
        ->get(route('custom-fields.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('fields.0.name', 'Estimate')
            ->where('can.manage', false),
        );
});

it('never shows another workspace its fields', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    [$elsewhere, $stranger] = workspaceWith(WorkspaceRole::Owner);

    app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);
    app(DefineCustomField::class)->handle($elsewhere, $stranger, 'Their field', CustomFieldType::Text);

    $this->actingAs($owner)
        ->get(route('custom-fields.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('fields', 1)
            ->where('fields.0.name', 'Estimate'),
        );
});

it('defines a field', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);

    $this->actingAs($owner)
        ->post(route('custom-fields.store'), ['name' => '  Estimate  ', 'type' => 'number'])
        ->assertRedirect();

    $field = CustomField::query()->sole();

    // Trimmed by the Action: the endpoint is a transport and nothing more.
    expect($field->name)->toBe('Estimate')
        ->and($field->type)->toBe(CustomFieldType::Number)
        ->and($field->workspace_id)->toBe($workspace->id);
});

it('defines a choice field and its choices in one request', function (): void {
    [, $owner] = workspaceWith(WorkspaceRole::Admin);

    $this->actingAs($owner)
        ->post(route('custom-fields.store'), [
            'name' => 'Size',
            'type' => 'select',
            'options' => ['Small', 'Large'],
        ])
        ->assertRedirect();

    $field = CustomField::query()->sole();

    expect($field->options->pluck('label')->all())->toBe(['Small', 'Large'])
        ->and($field->options->pluck('position')->all())->toBe([1, 2]);
});

it('refuses a choice field with nothing to choose from', function (): void {
    [, $owner] = workspaceWith(WorkspaceRole::Owner);

    $this->actingAs($owner)
        ->from(route('custom-fields.index'))
        ->post(route('custom-fields.store'), ['name' => 'Size', 'type' => 'select'])
        ->assertSessionHasErrors('options');

    expect(CustomField::query()->count())->toBe(0);
});

it('refuses a type that is not one', function (): void {
    [, $owner] = workspaceWith(WorkspaceRole::Owner);

    $this->actingAs($owner)
        ->from(route('custom-fields.index'))
        ->post(route('custom-fields.store'), ['name' => 'Estimate', 'type' => 'colour'])
        ->assertSessionHasErrors('type');
});

it('reports a name already taken on the field somebody can act on', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);

    /*
     * The unique index is what answers — two people can define "Estimate" in the same second —
     * so the refusal becomes an error on `name` rather than a toast pointing at nothing.
     */
    $this->actingAs($owner)
        ->from(route('custom-fields.index'))
        ->post(route('custom-fields.store'), ['name' => 'estimate', 'type' => 'text'])
        ->assertSessionHasErrors('name');

    expect(CustomField::query()->count())->toBe(1);
});

it('renames a field', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $field = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimatte', CustomFieldType::Text);

    $this->actingAs($owner)
        ->put(route('custom-fields.update', $field), ['name' => 'Estimate'])
        ->assertRedirect();

    expect($field->fresh()?->name)->toBe('Estimate')
        // The type is not editable here: it decides which column every answer already given
        // lives in.
        ->and($field->fresh()?->type)->toBe(CustomFieldType::Text);
});

it('ignores a type sent with a rename', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $field = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);

    $this->actingAs($owner)
        ->put(route('custom-fields.update', $field), ['name' => 'Estimate', 'type' => 'number'])
        ->assertRedirect();

    expect($field->fresh()?->type)->toBe(CustomFieldType::Text);
});

it('deletes a field and the answers that only meant something with it', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $field = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);

    $project = Project::factory()->in($workspace)->create();
    app(AttachFieldToProject::class)->handle($project, $field, $owner);

    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $this->actingAs($owner)->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => 'Two days']);

    $this->actingAs($owner)->delete(route('custom-fields.destroy', $field))->assertRedirect();

    expect(CustomField::query()->count())->toBe(0)
        ->and(TaskCustomFieldValue::query()->count())->toBe(0)
        ->and(CustomFieldOption::query()->count())->toBe(0);
});

it('refuses every write to somebody without custom_field.manage', function (WorkspaceRole $role): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $actor = memberOf($workspace, $role);
    $field = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);

    $this->actingAs($actor)->post(route('custom-fields.store'), ['name' => 'Size', 'type' => 'text'])->assertForbidden();
    $this->actingAs($actor)->put(route('custom-fields.update', $field), ['name' => 'Other'])->assertForbidden();
    $this->actingAs($actor)->delete(route('custom-fields.destroy', $field))->assertForbidden();

    expect(CustomField::query()->count())->toBe(1)
        ->and($field->fresh()?->name)->toBe('Estimate');
})->with([
    'member' => WorkspaceRole::Member,
    'guest' => WorkspaceRole::Guest,
]);

it('answers a field from another workspace with a 404', function (): void {
    [, $owner] = workspaceWith(WorkspaceRole::Owner);
    [$elsewhere, $stranger] = workspaceWith(WorkspaceRole::Owner);
    $theirs = app(DefineCustomField::class)->handle($elsewhere, $stranger, 'Their field', CustomFieldType::Text);

    // A 403 would confirm the id, which is what makes a leaked UUID worth something.
    $this->actingAs($owner)->put(route('custom-fields.update', $theirs), ['name' => 'Mine'])->assertNotFound();
    $this->actingAs($owner)->delete(route('custom-fields.destroy', $theirs))->assertNotFound();

    expect($theirs->fresh()?->name)->toBe('Their field');
});

it('turns nobody away who is not signed in', function (): void {
    $this->get(route('custom-fields.index'))->assertRedirect(route('login'));
});

/*
 * `email`, `phone` and `link` are text with a format: the server stores all four in `value_text`
 * and refuses the shape before the request reaches the database.
 */

it('defines a field of each text-shaped type', function (string $type): void {
    [, $owner] = workspaceWith(WorkspaceRole::Owner);

    $this->actingAs($owner)
        ->post(route('custom-fields.store'), ['name' => ucfirst($type), 'type' => $type])
        ->assertRedirect();

    expect(CustomField::query()->sole()->type)->toBe(CustomFieldType::from($type));
})->with(['email', 'phone', 'link']);

it('writes an address, a number to call and a link into the text column', function (
    CustomFieldType $type,
    string $sent,
): void {
    [$task, $field, $actor] = fieldOnATask($type);

    $this->actingAs($actor)
        ->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => $sent])
        ->assertRedirect();

    // One column for four types, and the field in hand says which of them this is.
    expect($task->customFieldValues()->sole()->value_text)->toBe($sent);
})->with([
    'email' => [CustomFieldType::Email, 'someone@example.com'],
    'phone' => [CustomFieldType::Phone, '+420 123 456 789'],
    'link' => [CustomFieldType::Link, 'https://example.com/handover'],
]);

it('refuses a value that is not the shape its type promises', function (
    CustomFieldType $type,
    string $sent,
): void {
    [$task, $field, $actor] = fieldOnATask($type);

    $this->actingAs($actor)
        ->from(route('tasks.show', $task))
        ->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => $sent])
        ->assertSessionHasErrors('value');

    expect(TaskCustomFieldValue::query()->count())->toBe(0);
})->with([
    'an address that is not one' => [CustomFieldType::Email, 'someone at example'],
    'a link that is not one' => [CustomFieldType::Link, 'not a link'],
    'a phone number that is prose' => [CustomFieldType::Phone, 'call the office'],
]);

it('clears a text-shaped answer the way every other type is cleared', function (): void {
    [$task, $field, $actor] = fieldOnATask(CustomFieldType::Email);
    $this->actingAs($actor)->put(route('tasks.custom-fields.update', [$task, $field]), [
        'value' => 'someone@example.com',
    ]);

    $this->actingAs($actor)
        ->put(route('tasks.custom-fields.update', [$task, $field]), ['value' => null])
        ->assertRedirect();

    expect(TaskCustomFieldValue::query()->count())->toBe(0);
});
