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
 * One task's answer for one field.
 *
 * Nothing is fillable: which task, which field and which column the answer belongs in are all
 * decided by the Action from the field's type, never by a payload — a request that could choose
 * the column could write a number into the text column and make sorting lie.
 *
 * @property string $id
 * @property string $task_id
 * @property string $custom_field_id
 * @property string|null $value_text
 * @property string|null $value_number
 * @property CarbonImmutable|null $value_date
 * @property bool|null $value_boolean
 * @property string|null $value_option_id
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

    /**
     * The answer, whichever column it lives in — read through the field's type so no caller has
     * to know the mapping.
     */
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
