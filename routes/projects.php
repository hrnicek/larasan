<?php

declare(strict_types=1);

use App\Domain\Project\Models\Project;
use App\Http\Controllers\Project\ProjectAppearanceController;
use App\Http\Controllers\Project\ProjectController;
use App\Http\Controllers\Project\ProjectMemberController;
use App\Http\Controllers\Project\ProjectNameController;
use App\Http\Controllers\Project\ProjectStarController;
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

    /*
     * Colour and icon, from the project's own header rather than from its settings form.
     * A route of its own because `projects.update` takes the whole form and reads an absent
     * nullable field as a deliberate clearing — a colour picked here would empty the
     * description and the dates the picker never showed.
     */
    Route::put('projects/{project}/appearance', [ProjectAppearanceController::class, 'update'])
        ->name('projects.appearance.update');

    /*
     * The name, from the sidebar's own menu, for the same reason: `projects.update` would take
     * the description and the dates the rename dialog never showed away with it. The slug is
     * not re-derived — that is a decision made in the settings form, where the consequence for
     * saved links can be seen.
     */
    Route::put('projects/{project}/name', [ProjectNameController::class, 'update'])
        ->name('projects.name.update');

    /*
     * A star is one person's shortcut, not a change to the project: anybody who may read the
     * project may star it, and the row it writes belongs to the actor alone.
     */
    Route::post('projects/{project}/star', [ProjectStarController::class, 'store'])->name('projects.star');
    Route::delete('projects/{project}/star', [ProjectStarController::class, 'destroy'])->name('projects.unstar');

    /*
     * Who may reach this project, and what they may do here. The project's own routes rather than
     * a variant of the workspace's: access inside a project is a different question from
     * membership of the workspace, and granting one changes nothing about the other (ADR-0006).
     */
    Route::post('projects/{project}/members', [ProjectMemberController::class, 'store'])
        ->name('projects.members.store');
    Route::put('projects/{project}/members/{membership}', [ProjectMemberController::class, 'update'])
        ->whereUuid('membership')
        ->name('projects.members.update');
    Route::delete('projects/{project}/members/{membership}', [ProjectMemberController::class, 'destroy'])
        ->whereUuid('membership')
        ->name('projects.members.destroy');

    Route::get('projects/{project}/settings', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');

    // Archiving is reversible and destroys nothing, so it is the state of a project
    // rather than a deletion: PUT sets it, DELETE clears it.
    Route::put('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::delete('projects/{project}/archive', [ProjectController::class, 'restore'])->name('projects.restore');
});
