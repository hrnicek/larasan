<?php

declare(strict_types=1);

namespace App\Http\Requests\CustomField;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\ResolveCurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = ResolveCurrentWorkspace::from($this);

        return $workspace instanceof Workspace
            && $this->user()?->can(Capability::CustomFieldManage->value, $workspace) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * Longer than a tag's forty and shorter than a title: a field's name is a column
             * heading on the list screen, and the name cell it competes with has a floor
             * (TASK-200-043).
             */
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::enum(CustomFieldType::class)],
            /*
             * A choice field arrives with its choices, because `DefineCustomField` creates the
             * two in one transaction — a choice field with no choices is a control nobody can
             * use. An empty array counts as absent here, which is what makes `required_if`
             * answer.
             */
            'options' => ['array', 'max:50', Rule::requiredIf(
                fn (): bool => $this->input('type') === CustomFieldType::Select->value,
            )],
            'options.*' => ['required', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'options.required' => __('A choice field needs at least one choice.'),
        ];
    }
}
