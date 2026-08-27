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
            /*
             * Rich text, so the ceiling is on markup rather than on prose: the same paragraph
             * that fitted in 5,000 characters as plain text carries tags now, and the limit is
             * meant to stop a payload rather than a description somebody meant to write.
             */
            'description' => ['nullable', 'string', 'max:20000'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'due_at' => ['nullable', 'date'],

            /*
             * Both ids are scoped to the workspace the request is in. A valid id from
             * another tenant deserves a validation error rather than a domain exception —
             * and without the scope, `exists` would happily confirm that somebody else's
             * task and somebody else's account exist.
             */
            'parent_id' => [
                'nullable', 'uuid',
                // Reach, not merely the workspace: a task inside a private project is in the
                // same workspace, and naming one as a parent read its title back through the
                // panel's breadcrumb (TASK-260-002).
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
     * The ids of the tasks this actor may reach, for a rule that has to check a reference.
     *
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
