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

        return $task instanceof Task && $this->user()?->can('update', $task) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $field = $this->route('field');

        return [
            'value' => ['nullable', ...($field instanceof CustomField ? $field->type->rules() : [])],
        ];
    }
}
