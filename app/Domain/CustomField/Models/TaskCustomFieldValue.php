<?php

declare(strict_types=1);

namespace App\Domain\CustomField\Models;

use App\Domain\Task\Models\Task;
use Carbon\CarbonImmutable;
use Database\Factories\TaskCustomFieldValueFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $task_id
 * @property string $custom_field_id
 * @property string|null $value_text
 * @property string|null $value_number
 * @property CarbonImmutable|null $value_date
 * @property bool|null $value_boolean
 * @property string|null $value_option_id
 * @property-read Task $task
 * @property-read CustomField $field
 */
#[UseFactory(TaskCustomFieldValueFactory::class)]
class TaskCustomFieldValue extends Model
{
    /** @use HasFactory<TaskCustomFieldValueFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<CustomField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    /** @return BelongsTo<CustomFieldOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(CustomFieldOption::class, 'value_option_id');
    }

    public function value(CustomField $field): string|float|bool|CarbonImmutable|null
    {
        /** @var string|float|bool|CarbonImmutable|null $value */
        $value = $this->getAttribute($field->type->column());

        return $value;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value_date' => 'immutable_date',
            'value_boolean' => 'boolean',
        ];
    }
}
