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
                /*
                 * Scoped to the tasks this actor can actually reach, and never the task
                 * itself. The workspace alone was not enough: a task inside a private project
                 * is in the same workspace, and naming one as a parent read its title back
                 * through the panel's breadcrumb. A longer loop is still the Action's to
                 * refuse — it needs the chain, not one comparison.
                 */
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
     * The ids of the tasks this actor may reach, for a rule that has to check a reference.
     *
     * @return EloquentBuilder<Task>
     */
    private function reachableTaskIds(?Workspace $workspace): EloquentBuilder
    {
        $user = $this->user();

        if (! $workspace instanceof Workspace || ! $user instanceof User) {
            // Nothing is reachable when there is nobody to reach it, and `authorize()` has
            // already refused by the time this could matter.
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
