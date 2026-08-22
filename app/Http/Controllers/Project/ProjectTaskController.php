<?php

declare(strict_types=1);

namespace App\Http\Controllers\Project;

use App\Domain\Placement\Actions\CreateTaskInProject;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Task\Data\CreateTaskData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectTaskRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Adding a task from the board or the list, where the column is part of what was asked for.
 * `tasks.store` remains the way to create a task that belongs to nothing yet.
 */
class ProjectTaskController extends Controller
{
    public function store(StoreProjectTaskRequest $request, Project $project, CreateTaskInProject $createTaskInProject): RedirectResponse
    {
        $createTaskInProject->handle(
            $project,
            $this->actor($request),
            new CreateTaskData(
                title: $request->string('title')->toString(),
                priority: $request->enum('priority', TaskPriority::class) ?? TaskPriority::Medium,
                dueAt: $request->date('due_at')?->toImmutable(),
                assigneeId: $request->integer('assignee_id') ?: null,
            ),
            $request->targetSection(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task added.')]);

        return back();
    }

    private function actor(StoreProjectTaskRequest $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(403);
    }
}
