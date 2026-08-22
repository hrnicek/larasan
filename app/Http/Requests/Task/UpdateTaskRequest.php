<?php

declare(strict_types=1);

namespace App\Http\Requests\Task;

use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Task\Models\Task;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->task();

        return $task instanceof Task
            && $this->user()?->can('update', $task) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $task = $this->task();

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'due_at' => ['nullable', 'date'],
            'parent_id' => [
                'nullable', 'uuid',
                // Scoped to the task's own workspace, and never the task itself. A longer
                // loop is the Action's to refuse: it needs the chain, not one comparison.
                Rule::exists('tasks', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('workspace_id', $task?->workspace_id)
                        ->whereNull('deleted_at'),
                ),
                Rule::notIn([$task?->id]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => __('That task is not in this workspace.'),
            'parent_id.not_in' => __('A task cannot be a subtask of itself.'),
        ];
    }

    private function task(): ?Task
    {
        $task = $this->route('task');

        return $task instanceof Task ? $task : null;
    }
}
