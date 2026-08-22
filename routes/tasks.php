<?php

declare(strict_types=1);

use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Queries\CurrentWorkspace;
use App\Http\Controllers\Task\TaskController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * `{task}` resolves inside the workspace the actor is currently in, the way projects and
 * sections already bind: a task from another workspace is indistinguishable from one that
 * does not exist.
 *
 * Within the workspace the policy answers, not the binding. A guest is a member of this
 * workspace and may not read its tasks, and 403 is the honest reply to somebody who is
 * standing in the right building — the 404 rule is about tenants, not roles.
 */
Route::bind('task', function (string $id): Task {
    $user = request()->user();

    if (! $user instanceof User) {
        abort(404);
    }

    $workspace = app(CurrentWorkspace::class)->for($user) ?? abort(404);

    return Task::query()->whereKey($id)->where('workspace_id', $workspace->id)->first() ?? abort(404);
});

Route::middleware(['auth', 'verified'])->whereUuid('task')->group(function (): void {
    // A task's own URL. The panel over a list is the list's URL plus `?task=`, because a
    // panel is a context plus a task and a copied link has to carry both (TASK-100-003).
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');

    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');

    // Completion is a state with two directions, as archiving is for a project: PUT sets
    // it, DELETE clears it.
    Route::put('tasks/{task}/completion', [TaskController::class, 'complete'])->name('tasks.complete');
    Route::delete('tasks/{task}/completion', [TaskController::class, 'reopen'])->name('tasks.reopen');

    Route::put('tasks/{task}/assignee', [TaskController::class, 'assign'])->name('tasks.assign');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
});
