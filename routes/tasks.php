<?php

declare(strict_types=1);

use App\Domain\Task\Models\Task;
use App\Http\Controllers\Task\TaskController;
use App\Http\Controllers\Task\TaskFollowerController;
use App\Http\Controllers\Task\TaskStarController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->whereUuid('task')->group(function (): void {
    /*
     * Declared before `tasks/{task}`: `create` is not a UUID, so `whereUuid('task')` would
     * refuse it anyway — but reading the file in the order the router matches it is worth more
     * than relying on that.
     */
    Route::get('tasks/create', [TaskController::class, 'create'])->name('tasks.create');

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

    // Watching is a state with two directions, like completion: POST starts, DELETE stops.
    /*
     * A star is one person's shortcut, not a subscription: anybody who may read the task may star
     * it, nobody is notified, and the row belongs to the actor alone.
     */
    Route::post('tasks/{task}/star', [TaskStarController::class, 'store'])->name('tasks.star');
    Route::delete('tasks/{task}/star', [TaskStarController::class, 'destroy'])->name('tasks.unstar');

    Route::post('tasks/{task}/followers', [TaskFollowerController::class, 'store'])->name('tasks.follow');
    Route::delete('tasks/{task}/followers', [TaskFollowerController::class, 'destroy'])->name('tasks.unfollow');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
});
