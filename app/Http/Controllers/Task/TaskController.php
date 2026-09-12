<?php

declare(strict_types=1);

namespace App\Http\Controllers\Task;

use App\Concerns\OpensTaskPanel;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Task\Actions\AssignTask;
use App\Domain\Task\Actions\CompleteTask;
use App\Domain\Task\Actions\CreateTask;
use App\Domain\Task\Actions\DeleteTask;
use App\Domain\Task\Actions\UpdateTask;
use App\Domain\Task\Data\CreateTaskData;
use App\Domain\Task\Data\UpdateTaskData;
use App\Domain\Task\Exceptions\TaskException;
use App\Domain\Task\Models\Task;
use App\Domain\Task\Queries\TaskDetailQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Requests\Task\AssignTaskRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InertiaUI\Modal\Modal;

class TaskController extends Controller
{
    use OpensTaskPanel;

    public function show(Request $request, Task $task, TaskDetailQuery $detail): Response
    {
        Gate::authorize('view', $task);

        $actor = $this->actor($request);

        if ($this->resolvesProp($request, 'task')) {
            $this->rememberOpening($task->workspace, $actor, $task);
        }

        return Inertia::render('tasks/Show', [
            ...$detail($task, $actor),
            'activity' => $this->taskActivity($task, $actor),
            ...$this->taskControlProps($task->workspace, $actor),
        ]);
    }

    public function create(Request $request, VisibleProjectsForUser $visibleProjects): Modal
    {
        $workspace = $this->currentWorkspace($request);
        $actor = $this->actor($request);

        Gate::authorize(Capability::TaskCreate->value, $workspace);

        $projects = $visibleProjects($workspace, $actor)
            // Set rather than lazy loaded, since the policy below reads each project's workspace.
            ->each(fn (Project $project) => $project->setRelation('workspace', $workspace))
            ->filter(fn (Project $project): bool => $actor->can('createTask', $project))
            ->values();

        $selected = $projects->firstWhere('id', $request->string('project')->value());

        return Inertia::modal('tasks/Create', [
            // Not `projects`: a page prop of that name would replace the shared sidebar prop.
            'targetProjects' => $projects
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'color' => $project->color?->value,
                ])
                ->all(),
            'project' => $selected?->id,
            'sections' => $selected instanceof Project
                ? $selected->sections()->orderBy('position')->get(['id', 'name'])
                    ->map(fn (Section $section): array => ['id' => $section->id, 'name' => $section->name])
                    ->all()
                : [],
            'section' => $request->string('section')->value() ?: null,
        ])->baseRoute('dashboard');
    }

    public function store(StoreTaskRequest $request, CreateTask $createTask): RedirectResponse
    {
        $this->translating(
            fn () => $createTask->handle(
                $this->currentWorkspace($request),
                $this->actor($request),
                CreateTaskData::fromRequest($request),
            ),
            'parent_id',
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task added.')]);

        return back();
    }

    public function update(UpdateTaskRequest $request, Task $task, UpdateTask $updateTask): RedirectResponse
    {
        $this->translating(
            fn () => $updateTask->handle($task, $this->actor($request), UpdateTaskData::fromRequest($request)),
            'parent_id',
        );

        return back();
    }

    public function complete(Request $request, Task $task, CompleteTask $completeTask): RedirectResponse
    {
        Gate::authorize('complete', $task);

        $completeTask->complete($task, $this->actor($request));

        return back();
    }

    public function reopen(Request $request, Task $task, CompleteTask $completeTask): RedirectResponse
    {
        Gate::authorize('complete', $task);

        $completeTask->reopen($task, $this->actor($request));

        return back();
    }

    public function assign(AssignTaskRequest $request, Task $task, AssignTask $assignTask): RedirectResponse
    {
        $assigneeId = $request->integer('assignee_id') ?: null;

        $assignTask->handle(
            $task,
            $this->actor($request),
            $assigneeId === null ? null : User::query()->findOrFail($assigneeId),
        );

        return back();
    }

    public function destroy(Request $request, Task $task, DeleteTask $deleteTask): RedirectResponse
    {
        Gate::authorize('delete', $task);

        $deleteTask->handle($task, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task deleted.')]);

        return back();
    }

    private function translating(callable $operation, string $field): void
    {
        try {
            $operation();
        } catch (TaskException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }

    private function currentWorkspace(Request $request): Workspace
    {
        return ResolveCurrentWorkspace::from($request) ?? abort(404);
    }
}
