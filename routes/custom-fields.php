<?php

declare(strict_types=1);

use App\Http\Controllers\CustomField\CustomFieldController;
use App\Http\Controllers\CustomField\ProjectCustomFieldController;
use App\Http\Controllers\CustomField\TaskCustomFieldController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    /*
     * What the workspace records, under `settings/` beside members for the reason those routes
     * carry no workspace parameter: the screen acts on the workspace the request is already in,
     * and `ResolveCurrentWorkspace` supplies it.
     */
    Route::get('settings/fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');
    Route::post('settings/fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
    Route::put('settings/fields/{field}', [CustomFieldController::class, 'update'])
        ->whereUuid('field')
        ->name('custom-fields.update');
    Route::delete('settings/fields/{field}', [CustomFieldController::class, 'destroy'])
        ->whereUuid('field')
        ->name('custom-fields.destroy');

    /*
     * Which of them a project shows. On the project, because adding a column to a board is a
     * decision about that board — the field itself is untouched, and detaching deliberately
     * keeps the answers.
     */
    Route::post('projects/{project}/custom-fields', [ProjectCustomFieldController::class, 'store'])
        ->whereUuid('project')
        ->name('projects.custom-fields.store');
    Route::delete('projects/{project}/custom-fields/{field}', [ProjectCustomFieldController::class, 'destroy'])
        ->whereUuid(['project', 'field'])
        ->name('projects.custom-fields.destroy');

    Route::put('tasks/{task}/custom-fields/{field}', [TaskCustomFieldController::class, 'update'])
        ->whereUuid(['task', 'field'])
        ->name('tasks.custom-fields.update');
});
