<?php

declare(strict_types=1);

namespace App\Http\Requests\Placement;

use App\Domain\Project\Models\Project;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\ReachableTasks;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->project();

        return $project instanceof Project
            && $this->user()?->can('placeTask', $project) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $project = $this->project();

        return [
            // Reachable tasks only, so `exists` confirms neither another tenant's tasks nor a private project's.
            'task' => [
                'required', 'uuid',
                Rule::exists('tasks', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('workspace_id', $project?->workspace_id)
                        ->whereNull('deleted_at')
                        ->whereIn('id', $this->reachableTaskIds($project?->workspace)),
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
            'task.exists' => __('That task is not in this workspace.'),
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

    private function project(): ?Project
    {
        $project = $this->route('project');

        return $project instanceof Project ? $project : null;
    }
}
