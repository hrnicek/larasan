<?php

declare(strict_types=1);

use App\Domain\CustomField\Actions\AttachFieldToProject;
use App\Domain\CustomField\Actions\DefineCustomField;
use App\Domain\CustomField\Actions\UpdateCustomFieldOptions;
use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\CustomFieldOption;
use App\Domain\CustomField\Models\TaskCustomFieldValue;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;

/*
 * The one operation Phase 150 left out: a choice field's choices were written once, when the field
 * was invented. The list is sent whole and reconciled — an id kept, an absent id removed, an entry
 * without one created.
 */

/**
 * A choice field with three choices, and the owner who may edit them.
 *
 * @return array{Workspace, CustomField, User}
 */
function choiceField(): array
{
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);

    $field = app(DefineCustomField::class)->handle(
        $workspace,
        $owner,
        'Stage',
        CustomFieldType::Select,
        ['Draft', 'Review', 'Done'],
    );

    return [$workspace, $field, $owner];
}

/**
 * @return list<array{id: ?string, label: string}>
 */
function asSent(CustomField $field): array
{
    return array_values($field->options()->get()
        ->map(fn (CustomFieldOption $option): array => ['id' => $option->id, 'label' => $option->label])
        ->all());
}

it('renames a choice, adds one and removes one in a single request', function (): void {
    [, $field, $owner] = choiceField();
    $existing = asSent($field);

    $this->actingAs($owner)
        ->put(route('custom-fields.options.update', $field), [
            'options' => [
                ['id' => $existing[0]['id'], 'label' => 'Drafting'],
                ['id' => null, 'label' => 'Blocked'],
                ['id' => $existing[2]['id'], 'label' => 'Done'],
            ],
        ])
        ->assertRedirect();

    $options = $field->fresh()?->options()->get();

    expect($options?->pluck('label')->all())->toBe(['Drafting', 'Blocked', 'Done'])
        ->and($options?->pluck('position')->all())->toBe([1, 2, 3])
        // The kept choice is the same row, so every answer that points at it still does.
        ->and($options?->first()?->id)->toBe($existing[0]['id']);
});

it('reorders without tripping the unique position index', function (): void {
    [, $field, $owner] = choiceField();
    $existing = asSent($field);

    // Exactly reversed: every row wants a position another row currently holds.
    $this->actingAs($owner)
        ->put(route('custom-fields.options.update', $field), [
            'options' => array_reverse($existing),
        ])
        ->assertRedirect();

    expect($field->fresh()?->options()->get()->pluck('label')->all())->toBe(['Done', 'Review', 'Draft']);
});

it('empties the answers that pointed at a removed choice', function (): void {
    [$workspace, $field, $owner] = choiceField();
    $existing = asSent($field);

    $project = Project::factory()->in($workspace)->create();
    app(AttachFieldToProject::class)->handle($project, $field, $owner);

    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $this->actingAs($owner)->put(route('tasks.custom-fields.update', [$task, $field]), [
        'value' => $existing[1]['id'],
    ]);

    expect(TaskCustomFieldValue::query()->count())->toBe(1);

    $this->actingAs($owner)->put(route('custom-fields.options.update', $field), [
        'options' => [$existing[0], $existing[2]],
    ])->assertRedirect();

    /*
     * `nullOnDelete` empties the column; the row is then an answer that says nothing, and
     * "no answer" and "an answer that is blank" are the same thing to a reader — the rule
     * `SetTaskCustomFieldValue` already keeps.
     */
    expect(TaskCustomFieldValue::query()->count())->toBe(0);
});

it('keeps the answers that pointed at a choice that stayed', function (): void {
    [$workspace, $field, $owner] = choiceField();
    $existing = asSent($field);

    $project = Project::factory()->in($workspace)->create();
    app(AttachFieldToProject::class)->handle($project, $field, $owner);

    $task = Task::factory()->in($workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();
    $this->actingAs($owner)->put(route('tasks.custom-fields.update', [$task, $field]), [
        'value' => $existing[0]['id'],
    ]);

    $this->actingAs($owner)->put(route('custom-fields.options.update', $field), [
        'options' => [
            ['id' => $existing[0]['id'], 'label' => 'Drafting'],
            $existing[1],
        ],
    ])->assertRedirect();

    expect($task->customFieldValues()->sole()->value_option_id)->toBe($existing[0]['id']);
});

it('refuses an empty list', function (): void {
    [, $field, $owner] = choiceField();

    $this->actingAs($owner)
        ->from(route('custom-fields.index'))
        ->put(route('custom-fields.options.update', $field), ['options' => []])
        ->assertSessionHasErrors('options');

    expect($field->options()->count())->toBe(3);
});

it('refuses a list of nothing but blank labels', function (): void {
    [, $field, $owner] = choiceField();

    // Validation cannot catch this one: each label is a string, and it is the trimming that
    // empties them.
    $this->actingAs($owner)
        ->from(route('custom-fields.index'))
        ->put(route('custom-fields.options.update', $field), ['options' => [['id' => null, 'label' => '   ']]])
        ->assertSessionHasErrors();

    expect($field->options()->count())->toBe(3);
});

it('refuses a field that offers no choices', function (): void {
    [$workspace, $owner] = workspaceWith(WorkspaceRole::Owner);
    $text = app(DefineCustomField::class)->handle($workspace, $owner, 'Estimate', CustomFieldType::Text);

    $this->actingAs($owner)
        ->from(route('custom-fields.index'))
        ->put(route('custom-fields.options.update', $text), ['options' => [['id' => null, 'label' => 'Small']]])
        ->assertSessionHasErrors();

    expect(CustomFieldOption::query()->count())->toBe(0);
});

it('refuses a choice belonging to another field', function (): void {
    [$workspace, $field, $owner] = choiceField();
    $other = app(DefineCustomField::class)->handle($workspace, $owner, 'Size', CustomFieldType::Select, ['Small']);
    $theirs = $other->options()->sole();

    expect(fn () => app(UpdateCustomFieldOptions::class)->handle($field, $owner, [
        ['id' => $theirs->id, 'label' => 'Stolen'],
    ]))->toThrow(CustomFieldException::class);

    expect($theirs->fresh()?->label)->toBe('Small')
        ->and($field->options()->count())->toBe(3);
});

it('refuses somebody without custom_field.manage', function (): void {
    [$workspace, $field] = choiceField();
    $member = memberOf($workspace, WorkspaceRole::Member);

    $this->actingAs($member)
        ->put(route('custom-fields.options.update', $field), [
            'options' => [['id' => null, 'label' => 'Whatever']],
        ])
        ->assertForbidden();

    expect($field->options()->count())->toBe(3);
});

it('answers a field from another workspace with a 404', function (): void {
    [, , $owner] = choiceField();
    [$elsewhere, $stranger] = workspaceWith(WorkspaceRole::Owner);
    $theirs = app(DefineCustomField::class)->handle($elsewhere, $stranger, 'Stage', CustomFieldType::Select, ['One']);

    $this->actingAs($owner)
        ->put(route('custom-fields.options.update', $theirs), [
            'options' => [['id' => null, 'label' => 'Two']],
        ])
        ->assertNotFound();

    expect($theirs->options()->count())->toBe(1);
});
