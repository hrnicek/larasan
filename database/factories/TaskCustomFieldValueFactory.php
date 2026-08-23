<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\TaskCustomFieldValue;
use App\Domain\Task\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskCustomFieldValue>
 */
class TaskCustomFieldValueFactory extends Factory
{
    protected $model = TaskCustomFieldValue::class;

    /**
     * Every value column is set explicitly to null: strict Eloquent throws on an attribute the
     * model never retrieved, and the CHECK allows at most one of them to be filled anyway.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'custom_field_id' => fn (array $attributes): string => CustomField::factory()->create([
                'workspace_id' => Task::query()->whereKey($attributes['task_id'])->value('workspace_id'),
            ])->id,
            'value_text' => null,
            'value_number' => null,
            'value_date' => null,
            'value_boolean' => null,
            'value_option_id' => null,
        ];
    }

    /**
     * The answer, written to the column the field's type says it belongs in.
     */
    public function answering(Task $task, CustomField $field, string|float|bool|null $value): self
    {
        return $this->state(fn (): array => [
            'task_id' => $task->id,
            'custom_field_id' => $field->id,
            $field->type->column() => $value,
        ]);
    }
}
