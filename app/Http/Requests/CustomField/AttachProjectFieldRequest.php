<?php

declare(strict_types=1);

namespace App\Http\Requests\CustomField;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachProjectFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can(Capability::CustomFieldManage->value, $project->workspace) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'field' => [
                'required',
                'uuid',
                Rule::exists('custom_fields', 'id')->where(
                    'workspace_id',
                    $project instanceof Project ? $project->workspace_id : null,
                ),
            ],
        ];
    }
}
