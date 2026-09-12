<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $values
 */
function insertValue(Task $task, string $fieldId, array $values = []): string
{
    $id = (string) Str::uuid7();

    DB::table('task_custom_field_values')->insert([
        'id' => $id,
        'task_id' => $task->id,
        'custom_field_id' => $fieldId,
        'value_text' => null,
        'value_number' => null,
        'value_date' => null,
        'value_boolean' => null,
        'value_option_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$values,
    ]);

    return $id;
}

it('attaches a field to a project once', function (): void {
    $workspace = Workspace::factory()->create();
    $project = Project::factory()->in($workspace)->create();
    $field = insertCustomField($workspace, 'Estimate');

    $row = [
        'id' => (string) Str::uuid7(),
        'project_id' => $project->id,
        'custom_field_id' => $field,
        'position' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('project_custom_fields')->insert($row);

    expect(fn () => DB::transaction(fn () => DB::table('project_custom_fields')->insert([...$row, 'id' => (string) Str::uuid7()])))
        ->toThrow(QueryException::class);
});

it('holds one answer per field per task', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $field = insertCustomField($workspace, 'Estimate');

    insertValue($task, $field, ['value_text' => 'Two days']);

    expect(fn (): string => DB::transaction(fn (): string => insertValue($task, $field, ['value_text' => 'Three days'])))
        ->toThrow(QueryException::class);
});

it('refuses a row with two answers in it', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $field = insertCustomField($workspace, 'Estimate');

    expect(fn (): string => DB::transaction(fn (): string => insertValue($task, $field, [
        'value_text' => 'Two days',
        'value_number' => 2,
    ])))->toThrow(QueryException::class);
});

it('allows a row with no answer at all', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $field = insertCustomField($workspace, 'Estimate');

    $id = insertValue($task, $field);

    expect(DB::table('task_custom_field_values')->where('id', $id)->exists())->toBeTrue();
});

it('keeps an exact number rather than a float', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $field = insertCustomField($workspace, 'Budget', ['type' => CustomFieldType::Number->value]);

    insertValue($task, $field, ['value_number' => '1234567890.123456']);

    expect(DB::table('task_custom_field_values')->value('value_number'))->toBe('1234567890.123456');
});

it('keeps the answer when the option it named is deleted', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $field = insertCustomField($workspace, 'Stage', ['type' => CustomFieldType::Select->value]);
    $option = insertOption($field, 'Draft', 1);

    insertValue($task, $field, ['value_option_id' => $option]);

    DB::table('custom_field_options')->where('id', $option)->delete();

    $value = DB::table('task_custom_field_values')->first();

    expect($value)->not->toBeNull()
        ->and($value?->value_option_id)->toBeNull();
});

it('goes when the task or the field goes', function (): void {
    $workspace = Workspace::factory()->create();
    $task = Task::factory()->in($workspace)->create();
    $field = insertCustomField($workspace, 'Estimate');
    insertValue($task, $field, ['value_text' => 'Two days']);

    DB::table('custom_fields')->where('id', $field)->delete();

    expect(DB::table('task_custom_field_values')->count())->toBe(0);
});

it('indexes each value column under its field', function (): void {
    $indexes = collect(Schema::getIndexes('task_custom_field_values'))->pluck('columns');

    expect($indexes)->toContain(['custom_field_id', 'value_text'])
        ->and($indexes)->toContain(['custom_field_id', 'value_number'])
        ->and($indexes)->toContain(['custom_field_id', 'value_date'])
        ->and($indexes)->toContain(['custom_field_id', 'value_option_id'])
        ->and($indexes)->toContain(['task_id', 'custom_field_id']);
});

it('indexes the option a value points at', function (): void {
    $indexes = collect(Schema::getIndexes('task_custom_field_values'))->pluck('columns');

    // PostgreSQL does not index the referencing side of a foreign key, and nullOnDelete must find these rows.
    expect($indexes)->toContain(['value_option_id']);
});

it('finds the answers to null by the option rather than by scanning', function (): void {
    $workspace = Workspace::factory()->create();
    $field = insertCustomField($workspace, 'Stage', ['type' => CustomFieldType::Select->value]);
    $option = insertOption($field, 'Draft', 1);

    // Enough analysed rows that the planner is choosing from statistics.
    $tasks = Task::factory()->in($workspace)->count(100)->create()->pluck('id')->all();
    $rows = [];

    foreach (range(1, 30) as $index) {
        $other = insertCustomField($workspace, "Field {$index}", ['type' => CustomFieldType::Select->value]);
        $otherOption = insertOption($other, 'Something', 1);

        foreach ($tasks as $taskId) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'task_id' => $taskId,
                'custom_field_id' => $index === 1 ? $field : $other,
                'value_text' => null,
                'value_number' => null,
                'value_date' => null,
                'value_boolean' => null,
                'value_option_id' => $index === 1 ? $option : $otherOption,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('task_custom_field_values')->insert($chunk);
    }

    DB::statement('ANALYZE task_custom_field_values');

    $query = DB::table('task_custom_field_values')->where('value_option_id', $option);
    $explained = DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());

    /** @var string $json */
    $json = ((array) $explained[0])['QUERY PLAN'];
    $plan = (string) json_encode(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

    expect($plan)->toContain('task_custom_field_values_value_option_id_index')
        ->and($plan)->not->toContain('Seq Scan');
});
