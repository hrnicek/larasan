<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Workspace\Queries\CurrentWorkspace;
use App\Http\Controllers\Project\ProjectController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * The {project} parameter resolves inside the workspace the actor is currently in, and
 * only among the projects they may see. A project from another workspace, or a private
 * one they were never given, is indistinguishable from one that does not exist — 403
 * would confirm the id, which is what makes a leaked UUID worth something.
 *
 * Resolved here rather than by implicit binding plus a check afterwards: the binding runs
 * before this application's middleware in the web group, so a check placed after it would
 * depend on an ordering that is easy to break and silent when broken. Archived projects
 * still resolve — their settings screen is where they are restored.
 */
Route::bind('project', function (string $id): Project {
    $user = request()->user();

    if (! $user instanceof User) {
        abort(404);
    }

    $workspace = app(CurrentWorkspace::class)->for($user) ?? abort(404);

    return app(VisibleProjectsForUser::class)
        ->query($workspace, $user, includeArchived: true)
        ->whereKey($id)
        ->first() ?? abort(404);
});

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

    Route::get('projects/{project}/settings', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');

    // Archiving is reversible and destroys nothing, so it is the state of a project
    // rather than a deletion: PUT sets it, DELETE clears it.
    Route::put('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::delete('projects/{project}/archive', [ProjectController::class, 'restore'])->name('projects.restore');
});
