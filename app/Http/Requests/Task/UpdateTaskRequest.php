<?php

declare(strict_types=1);

namespace App\Http\Requests\Task;

use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\ReachableTasks;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            // The limit counts rich-text markup, not only prose.
            'description' => ['nullable', 'string', 'max:20000'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'due_at' => ['nullable', 'date'],
            'parent_id' => [
                'nullable', 'uuid',
                // Reachable tasks only, so a task in a private project cannot be exposed as a parent.
                // Longer cycles are refused by the Action.
                Rule::exists('tasks', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('workspace_id', $task?->workspace_id)
                        ->whereNull('deleted_at')
                        ->whereIn('id', $this->reachableTaskIds($task?->workspace)),
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

    /**
     * @return EloquentBuilder<Task>
     */
    private function reachableTaskIds(?Workspace $workspace): EloquentBuilder
    {
        $user = $this->user();

        if (! $workspace instanceof Workspace || ! $user instanceof User) {
            return Task::query()->whereRaw('1 = 0')->select('tasks.id');
        }

        return app(ReachableTasks::class)->idsFor($workspace, $user);
    }

    private function task(): ?Task
    {
        $task = $this->route('task');

        return $task instanceof Task ? $task : null;
    }
}
