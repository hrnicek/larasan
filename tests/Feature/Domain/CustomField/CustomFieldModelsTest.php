<?php

declare(strict_types=1);

use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\CustomFieldOption;
use App\Domain\CustomField\Models\ProjectCustomField;
use App\Domain\CustomField\Models\TaskCustomFieldValue;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\MassAssignmentException;

it('refuses to have its type mass assigned', function (): void {
    /*
     * The type decides which column every value of this field lives in, so changing it would
     * leave the answers people have already given in a column nothing reads. That is a
     * migration of data, not an edit.
     */
    expect(fn (): CustomField => (new CustomField)->fill(['name' => 'Estimate', 'type' => 'number']))
        ->toThrow(MassAssignmentException::class);

    expect(fn (): CustomField => (new CustomField)->fill(['workspace_id' => 'anything']))
        ->toThrow(MassAssignmentException::class);
});

it('refuses to have a value s task, field or column mass assigned', function (): void {
    // A request that could choose the column could write a number into the text column and make
    // sorting lie.
    foreach (['task_id', 'custom_field_id', 'value_text', 'value_number'] as $attribute) {
        expect(fn (): TaskCustomFieldValue => (new TaskCustomFieldValue)->fill([$attribute => 'anything']))
            ->toThrow(MassAssignmentException::class);
    }
});

it('reads its options in the order the screen draws them', function (): void {
    $workspace = Workspace::factory()->create();
    $field = CustomField::factory()->in($workspace)->ofType(CustomFieldType::Select)->create();

    CustomFieldOption::factory()->of($field, 3)->labelled('Done')->create();
    CustomFieldOption::factory()->of($field, 1)->labelled('Draft')->create();
    CustomFieldOption::factory()->of($field, 2)->labelled('Review')->create();

    expect($field->options()->pluck('label')->all())->toBe(['Draft', 'Review', 'Done']);
});

it('reads an answer through the field s own type', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();

    $text = CustomField::factory()->in($workspace)->create();
    $number = CustomField::factory()->in($workspace)->ofType(CustomFieldType::Number)->create();
    $flag = CustomField::factory()->in($workspace)->ofType(CustomFieldType::Boolean)->create();

    $answers = [
        TaskCustomFieldValue::factory()->answering($task, $text, 'Two days')->create(),
        TaskCustomFieldValue::factory()->answering($task, $number, 12.5)->create(),
        TaskCustomFieldValue::factory()->answering($task, $flag, true)->create(),
    ];

    // No caller has to know the mapping: the field says which column its answer is in.
    expect($answers[0]->value($text))->toBe('Two days')
        ->and((float) $answers[1]->value($number))->toBe(12.5)
        ->and($answers[2]->value($flag))->toBeTrue();
});

it('keeps a factory field, option and value in one workspace', function (): void {
    $value = TaskCustomFieldValue::factory()->create();

    // A value whose field belongs to another workspace is a row the domain will never create,
    // and a factory that produced one would hand every later test a lie.
    expect($value->field->workspace_id)->toBe($value->task->workspace_id);

    $attached = ProjectCustomField::factory()->create();

    expect($attached->field->workspace_id)->toBe($attached->project->workspace_id);
});

it('carries an option colour from the shared palette', function (): void {
    $option = CustomFieldOption::factory()->create(['color' => ProjectColor::Sky]);

    expect($option->fresh()?->color)->toBe(ProjectColor::Sky);
});

it('hangs a field from its workspace and its values from the field', function (): void {
    $workspace = Workspace::factory()->create();
    $field = CustomField::factory()->in($workspace)->create();
    $task = Task::factory()->in($workspace)->create();
    TaskCustomFieldValue::factory()->answering($task, $field, 'Yes')->create();

    expect($field->workspace->is($workspace))->toBeTrue()
        ->and($field->values()->count())->toBe(1);
});

it('attaches a field to a project at a position', function (): void {
    $workspace = Workspace::factory()->create();
    $project = Project::factory()->in($workspace)->create();
    $field = CustomField::factory()->in($workspace)->create();

    $attached = ProjectCustomField::factory()->attaching($project, $field, 2)->create();

    expect($attached->position)->toBe(2)
        ->and($attached->field->is($field))->toBeTrue()
        ->and($attached->project->is($project))->toBeTrue();
});
