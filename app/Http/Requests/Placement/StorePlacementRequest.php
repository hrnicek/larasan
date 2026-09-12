<?php

declare(strict_types=1);

namespace App\Http\Requests\Placement;

use App\Domain\Project\Models\Project;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->project();

        return $project instanceof Project
            && $this->user()?->can('placeTask', $project) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $project = $this->project();

        return [
            // Scoped to the workspace, so `exists` cannot confirm another tenant's tasks.
            'task' => [
                'required', 'uuid',
                Rule::exists('tasks', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('workspace_id', $project?->workspace_id)
                        ->whereNull('deleted_at'),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'task.exists' => __('That task is not in this workspace.'),
        ];
    }

    private function project(): ?Project
    {
        $project = $this->route('project');

        return $project instanceof Project ? $project : null;
    }
}
