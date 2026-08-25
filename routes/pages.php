<?php

declare(strict_types=1);

use App\Http\Controllers\Page\PageContentController;
use App\Http\Controllers\Page\PageController;
use App\Http\Controllers\Page\PagePlacementController;
use App\Http\Controllers\Page\PageTitleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->whereUuid(['project', 'page'])->group(function (): void {
    /*
     * A page is written inside a project, so the project is in the URL; afterwards it has an
     * id of its own and the project is no longer needed to address it. Throttled because a
     * page is cheap here and each one is a row in somebody else's sidebar.
     */
    Route::post('projects/{project}/pages', [PageController::class, 'store'])
        ->middleware('throttle:page-creation')
        ->name('projects.pages.store');

    // The page itself, with the project's tree beside it.
    Route::get('pages/{page}', [PageController::class, 'show'])->name('pages.show');

    Route::put('pages/{page}/title', [PageTitleController::class, 'update'])->name('pages.title.update');

    /*
     * Autosave. Throttled harder than a form endpoint and no harder than typing: the client
     * saves after a pause, so a person writing continuously produces a handful of requests a
     * minute, and anything above that is a client in a loop.
     */
    Route::put('pages/{page}/content', [PageContentController::class, 'update'])
        ->middleware('throttle:page-saves')
        ->name('pages.content.update');

    Route::put('pages/{page}/placement', [PagePlacementController::class, 'update'])->name('pages.placement.update');

    Route::delete('pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');
});
