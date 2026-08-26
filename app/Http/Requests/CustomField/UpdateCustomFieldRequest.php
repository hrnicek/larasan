<?php

declare(strict_types=1);

namespace App\Http\Requests\CustomField;

use App\Domain\CustomField\Models\CustomField;
use App\Domain\Shared\Enums\Capability;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $field = $this->route('field');

        return $field instanceof CustomField
            && $this->user()?->can(Capability::CustomFieldManage->value, $field->workspace) === true;
    }

    /**
     * The name and nothing else: a field's type decides which column every answer already given
     * lives in, so changing it is a migration of that data rather than an edit (`CustomField`).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
        ];
    }
}
