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
            /*
             * `sometimes`, so a row editing one field does not have to send the whole task
             * back — and `required` when it is there, because a title is not something that
             * can be blanked.
             */
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            /*
             * Rich text, so the ceiling is on markup rather than on prose: the same paragraph
             * that fitted in 5,000 characters as plain text carries tags now, and the limit is
             * meant to stop a payload rather than a description somebody meant to write.
             */
            'description' => ['nullable', 'string', 'max:20000'],
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
