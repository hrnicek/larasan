<?php

declare(strict_types=1);

namespace App\Http\Controllers\Task;

use App\Domain\Task\Actions\AddTaskCollaborator;
use App\Domain\Task\Actions\RemoveTaskCollaborator;
use App\Domain\Task\Models\Task;
use App\Http\Controllers\Controller;
use App\Http\Requests\Task\AddTaskCollaboratorRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TaskCollaboratorController extends Controller
{
    public function store(AddTaskCollaboratorRequest $request, Task $task, AddTaskCollaborator $addCollaborator): RedirectResponse
    {
        $addCollaborator->handle(
            $task,
            $this->actor($request),
            User::query()->findOrFail($request->integer('user_id')),
        );

        return back();
    }

    /**
     * An id rather than a bound `User`, so the response never reveals whether an account exists.
     */
    public function destroy(Request $request, Task $task, int $collaborator, RemoveTaskCollaborator $removeCollaborator): RedirectResponse
    {
        $actor = $this->actor($request);

        if ($actor->id !== $collaborator) {
            Gate::authorize('assign', $task);
        }

        $removeCollaborator->handle($task, $actor, $collaborator);

        return back();
    }
}
