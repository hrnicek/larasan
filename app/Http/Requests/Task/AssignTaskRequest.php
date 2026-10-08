<?php

declare(strict_types=1);

namespace App\Http\Requests\Task;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Task\Models\Task;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->task();

        return $task instanceof Task
            && $this->user()?->can('assign', $task) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $task = $this->task();

        return [
            // Scoped to the workspace, so `exists` cannot confirm another tenant's accounts.
            'assignee_id' => [
                'nullable', 'integer',
                Rule::exists('workspace_memberships', 'user_id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('workspace_id', $task?->workspace_id)
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
            'assignee_id.exists' => __('That person is not an active member of this workspace.'),
        ];
    }

    private function task(): ?Task
    {
        $task = $this->route('task');

        return $task instanceof Task ? $task : null;
    }
}
