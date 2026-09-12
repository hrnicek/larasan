<?php

declare(strict_types=1);

use App\Http\Controllers\Project\ProjectAppearanceController;
use App\Http\Controllers\Project\ProjectColumnController;
use App\Http\Controllers\Project\ProjectController;
use App\Http\Controllers\Project\ProjectMemberController;
use App\Http\Controllers\Project\ProjectNameController;
use App\Http\Controllers\Project\ProjectStarController;
use App\Http\Controllers\Project\ProjectTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->whereUuid('project')->group(function (): void {
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('projects', [ProjectController::class, 'store'])
        ->middleware('throttle:project-creation')
        ->name('projects.store');

    // `?view=` overrides `projects.default_view` for one request.
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');

    Route::post('projects/{project}/tasks', [ProjectTaskController::class, 'store'])->name('projects.tasks.store');

    // Appearance and name get their own routes: `projects.update` clears absent nullable fields.
    Route::put('projects/{project}/appearance', [ProjectAppearanceController::class, 'update'])
        ->name('projects.appearance.update');

    Route::put('projects/{project}/name', [ProjectNameController::class, 'update'])
        ->name('projects.name.update');

    Route::post('projects/{project}/star', [ProjectStarController::class, 'store'])->name('projects.star');
    Route::delete('projects/{project}/star', [ProjectStarController::class, 'destroy'])->name('projects.unstar');

    Route::post('projects/{project}/members', [ProjectMemberController::class, 'store'])
        ->name('projects.members.store');
    Route::put('projects/{project}/members/{membership}', [ProjectMemberController::class, 'update'])
        ->whereUuid('membership')
        ->name('projects.members.update');
    Route::delete('projects/{project}/members/{membership}', [ProjectMemberController::class, 'destroy'])
        ->whereUuid('membership')
        ->name('projects.members.destroy');

    Route::put('projects/{project}/columns', [ProjectColumnController::class, 'update'])
        ->name('projects.columns.update');

    Route::get('projects/{project}/settings', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');

    Route::put('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::delete('projects/{project}/archive', [ProjectController::class, 'restore'])->name('projects.restore');
});
