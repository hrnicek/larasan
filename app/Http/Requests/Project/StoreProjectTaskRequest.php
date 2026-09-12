<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Domain\Project\Models\Project;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->project();

        return $project instanceof Project
            && $this->user()?->can('createTask', $project) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $project = $this->project();

        return [
            'title' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'due_at' => ['nullable', 'date'],

            'section' => [
                'nullable', 'uuid',
                Rule::exists('sections', 'id')->where(
                    fn (Builder $query): Builder => $query->where('project_id', $project?->id),
                ),
            ],

            // Scoped to the workspace, so `exists` cannot confirm another tenant's accounts.
            'assignee_id' => [
                'nullable', 'integer',
                Rule::exists('workspace_memberships', 'user_id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('workspace_id', $project?->workspace_id)
                        ->where('status', WorkspaceMembershipStatus::Active->value),
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
            'section.exists' => __('That section is not in this project.'),
            'assignee_id.exists' => __('That person is not an active member of this workspace.'),
        ];
    }

    /**
     * Scoped to the project even when validation is bypassed.
     */
    public function targetSection(): ?Section
    {
        $section = $this->string('section')->value() ?: null;
        $project = $this->project();

        if ($section === null || ! $project instanceof Project) {
            return null;
        }

        return $project->sections()->whereKey($section)->firstOrFail();
    }

    private function project(): ?Project
    {
        $project = $this->route('project');

        return $project instanceof Project ? $project : null;
    }
}
