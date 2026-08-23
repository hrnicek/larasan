<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Http\Controllers\Project\ProjectController;
use App\Http\Controllers\Project\ProjectTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->whereUuid('project')->group(function (): void {
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
    /*
     * Throttled like workspace creation: a project is cheap to create and each one seeds
     * a membership row and an event, so an unbounded endpoint is a way to fill a tenant's
     * sidebar faster than anyone can clean it up.
     */
    Route::post('projects', [ProjectController::class, 'store'])
        ->middleware('throttle:project-creation')
        ->name('projects.store');

    // The project itself. `?view=` overrides `projects.default_view` for one request, so the
    // URL is the state and a shared link shows what the sender saw.
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');

    // Adding a task where you are looking: the task and its card, in one request.
    Route::post('projects/{project}/tasks', [ProjectTaskController::class, 'store'])->name('projects.tasks.store');

    Route::get('projects/{project}/settings', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');

    // Archiving is reversible and destroys nothing, so it is the state of a project
    // rather than a deletion: PUT sets it, DELETE clears it.
    Route::put('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::delete('projects/{project}/archive', [ProjectController::class, 'restore'])->name('projects.restore');
});
