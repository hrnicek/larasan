<?php

declare(strict_types=1);

namespace App\Http\Requests\CustomField;

use App\Domain\CustomField\Models\CustomField;
use App\Domain\Task\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

class SetCustomFieldValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        // Filling a field in is editing the task, so the answer is a 403 rather than a rendered
        // refusal — the Action asks the same question again for callers without a request.
        return $task instanceof Task && $this->user()?->can('update', $task) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $field = $this->route('field');

        /*
         * The rules come from the field's own type (`CustomFieldType::rules()`), so "what may be
         * written here" is stated once and read by validation, storage and sorting alike. A
         * `nullable` in front of them is what makes clearing a value an ordinary request rather
         * than a second endpoint.
         */
        return [
            'value' => ['nullable', ...($field instanceof CustomField ? $field->type->rules() : [])],
        ];
    }
}
