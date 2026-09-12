<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use Illuminate\Foundation\Http\FormRequest;

class ReorderProjectColumnsRequest extends FormRequest
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
        return [
            'columns' => ['present', 'array', 'max:60'],
            'columns.*' => ['required', 'string', 'max:64'],
        ];
    }
}
