<?php

declare(strict_types=1);

namespace App\Http\Requests\Project;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantProjectAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('manageMembers', $project) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            // Scoped to the workspace, so `exists` cannot confirm another tenant's accounts.
            'user' => [
                'required',
                Rule::exists('workspace_memberships', 'user_id')->where(
                    'workspace_id',
                    $project instanceof Project ? $project->workspace_id : null,
                ),
            ],
            'access_level' => ['required', Rule::enum(ProjectAccessLevel::class)],
        ];
    }
}
