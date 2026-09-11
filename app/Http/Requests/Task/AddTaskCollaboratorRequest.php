<?php

declare(strict_types=1);

namespace App\Http\Requests\Task;

use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Task\Models\Task;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Putting somebody on a task is assigning it, so it asks what `AssignTaskRequest` asks.
 */
class AddTaskCollaboratorRequest extends FormRequest
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
            // Scoped to this task's workspace: an unscoped `exists` would confirm that any
            // account in the installation exists.
            'user_id' => [
                'required', 'integer',
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
            'user_id.exists' => __('That person is not an active member of this workspace.'),
        ];
    }

    private function task(): ?Task
    {
        $task = $this->route('task');

        return $task instanceof Task ? $task : null;
    }
}
