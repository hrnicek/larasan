<?php

declare(strict_types=1);

namespace App\Http\Requests\Task;

use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\ReachableTasks;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = ResolveCurrentWorkspace::from($this);

        return $workspace instanceof Workspace
            && $this->user()?->can(Capability::TaskCreate->value, $workspace) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $workspace = ResolveCurrentWorkspace::from($this);

        return [
            'title' => ['required', 'string', 'max:255'],
            // The limit counts rich-text markup, not only prose.
            'description' => ['nullable', 'string', 'max:20000'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'due_at' => ['nullable', 'date'],

            // Scoped to this workspace, so `exists` cannot confirm another tenant's records.
            'parent_id' => [
                'nullable', 'uuid',
                // Reachable tasks only, so a task in a private project cannot be exposed as a parent.
                Rule::exists('tasks', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace?->id)
                        ->whereNull('deleted_at')
                        ->whereIn('id', $this->reachableTaskIds($workspace)),
                ),
            ],
            'assignee_id' => [
                'nullable', 'integer',
                Rule::exists('workspace_memberships', 'user_id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace?->id)
                        ->where('status', WorkspaceMembershipStatus::Active->value),
                ),
            ],
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => __('That task is not in this workspace.'),
            'assignee_id.exists' => __('That person is not an active member of this workspace.'),
        ];
    }
}
