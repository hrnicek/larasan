<?php

declare(strict_types=1);

namespace App\Http\Controllers\Task;

use App\Domain\Activity\Queries\TaskFeedQuery;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\TaskPriority;
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
use Inertia\DeferProp;
use Inertia\Inertia;
use Inertia\Response;
use InertiaUI\Modal\Modal;

/**
 * Tasks are created and edited in place — in a list, on a board, in the detail panel — so
 * every method answers with a redirect back. The screens that render them arrive in Phases
 * 080 to 100; these endpoints exist so those screens have something to call, and so the
 * console and the future API share exactly one path into the domain.
 */
class TaskController extends Controller
{
    /**
     * One task, at a real and addressable URL.
     *
     * The same payload the panel renders, because the panel and this page are one component
     * (TASK-100-003): two would drift, and the second would be the one nobody tests.
     */
    public function show(Request $request, Task $task, TaskDetailQuery $detail): Response
    {
        Gate::authorize('view', $task);

        $actor = $this->actor($request);

        return Inertia::render('tasks/Show', [
            ...$detail($task, $actor),
            /*
             * The same two lists the project screen sends, because the panel's field controls
             * are the same components the list row uses — a second copy of either list would
             * be the one that goes stale.
             */
            'members' => $task->workspace->members()->orderBy('name')->get()
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => null,
                ])
                ->values()
                ->all(),
            'priorities' => array_column(TaskPriority::cases(), 'value'),
            'activity' => $this->activity($task, $actor),
        ]);
    }

    /**
     * The task's history and its conversation, deferred.
     *
     * The only deferred region in the application: activity and comments are secondary and can
     * be slow, while the fields above them are worth reading immediately. The region has been
     * answering with an empty array since TASK-100-011; TASK-110-009 gives it the thread.
     */
    private function activity(Task $task, User $actor): DeferProp
    {
        return Inertia::defer(fn (): array => app(TaskFeedQuery::class)($task, $actor));
    }

    /**
     * The form behind every way of adding a task that is not a row in a list.
     *
     * A project is a required field here rather than an optional one, which is the difference
     * between this and the inline row: the row already knows where it is, and somebody adding a
     * task from the topbar has told us nothing yet. `project` and `section` prefill it when the
     * caller knows them, and stay editable — a prefilled field the person cannot see is a field
     * they will fight.
     */
    public function create(Request $request, VisibleProjectsForUser $visibleProjects): Modal
    {
        $workspace = $this->currentWorkspace($request);
        $actor = $this->actor($request);

        Gate::authorize(Capability::TaskCreate->value, $workspace);

        /*
         * Only the projects this person may add to. Visibility is not the question — being able
         * to read a project is not being able to put work in it — so the policy decides each one
         * after the query has narrowed them to the ones they can see at all.
         */
        $projects = $visibleProjects($workspace, $actor)
            /*
             * The workspace is handed to each project rather than loaded: the query is already
             * scoped to this one, so every row belongs to it, and the policy below reads
             * `$project->workspace` for each. Without this it is a lazy-load violation on the
             * first row and an N+1 the moment the guard is off.
             */
            ->each(fn (Project $project) => $project->setRelation('workspace', $workspace))
            ->filter(fn (Project $project): bool => $actor->can('createTask', $project))
            ->values();

        $selected = $projects->firstWhere('id', $request->string('project')->value());

        return Inertia::modal('tasks/Create', [
            'projects' => $projects
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'color' => $project->color?->value,
                ])
                ->all(),
            'project' => $selected?->id,
            // Only the chosen project's columns, and only when one is chosen. A section list for
            // a project nobody selected is a list of somewhere else's columns.
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
        /*
         * The request scopes the parent to this workspace and refuses the task itself; it
         * cannot walk the chain, so a longer loop reaches the Action. Translated here
         * because a refusal the user can act on belongs on the field they chose, and the
         * Action does not know it is being called over HTTP.
         */
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

    /**
     * Domain refusals become validation errors on the field the user can act on, the way
     * `WorkspaceMemberController` already translates them.
     */
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
