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
     * The list whole, in the order it should be offered. An entry with an id is one that already
     * exists; one without is new; an id left out is removed.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // `min:1` because a choice field with no choices is a control nobody can use — the
            // Action refuses it too, for the callers that are not this one.
            'options' => ['required', 'array', 'min:1', 'max:50'],
            'options.*.id' => ['nullable', 'uuid'],
            'options.*.label' => ['required', 'string', 'max:60'],
        ];
    }
}
