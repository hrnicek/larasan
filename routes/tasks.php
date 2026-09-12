<?php

declare(strict_types=1);

use App\Http\Controllers\Task\TaskCollaboratorController;
use App\Http\Controllers\Task\TaskController;
use App\Http\Controllers\Task\TaskFollowerController;
use App\Http\Controllers\Task\TaskStarController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->whereUuid('task')->group(function (): void {
    Route::get('tasks/create', [TaskController::class, 'create'])->name('tasks.create');

    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');

    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');

    Route::put('tasks/{task}/completion', [TaskController::class, 'complete'])->name('tasks.complete');
    Route::delete('tasks/{task}/completion', [TaskController::class, 'reopen'])->name('tasks.reopen');

    Route::put('tasks/{task}/assignee', [TaskController::class, 'assign'])->name('tasks.assign');

    Route::post('tasks/{task}/collaborators', [TaskCollaboratorController::class, 'store'])->name('tasks.collaborators.store');
    Route::delete('tasks/{task}/collaborators/{collaborator}', [TaskCollaboratorController::class, 'destroy'])
        ->whereNumber('collaborator')
        ->name('tasks.collaborators.destroy');

    Route::post('tasks/{task}/star', [TaskStarController::class, 'store'])->name('tasks.star');
    Route::delete('tasks/{task}/star', [TaskStarController::class, 'destroy'])->name('tasks.unstar');

    Route::post('tasks/{task}/followers', [TaskFollowerController::class, 'store'])->name('tasks.follow');
    Route::delete('tasks/{task}/followers', [TaskFollowerController::class, 'destroy'])->name('tasks.unfollow');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
});
