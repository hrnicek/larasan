<?php

declare(strict_types=1);

namespace App\Http\Requests\CustomField;

use App\Domain\CustomField\Models\CustomField;
use App\Domain\Shared\Enums\Capability;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomFieldOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $field = $this->route('field');

        return $field instanceof CustomField
            && $this->user()?->can(Capability::CustomFieldManage->value, $field->workspace) === true;
    }

    /**
     * Replaces the whole list: entries without an id are created, and omitted ids are removed.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'options' => ['required', 'array', 'min:1', 'max:50'],
            'options.*.id' => ['nullable', 'uuid'],
            'options.*.label' => ['required', 'string', 'max:60'],
        ];
    }
}
