<?php

declare(strict_types=1);

use App\Http\Controllers\Page\PageContentController;
use App\Http\Controllers\Page\PageController;
use App\Http\Controllers\Page\PagePlacementController;
use App\Http\Controllers\Page\PageTitleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->whereUuid(['project', 'page'])->group(function (): void {
    Route::post('projects/{project}/pages', [PageController::class, 'store'])
        ->middleware('throttle:page-creation')
        ->name('projects.pages.store');

    Route::get('pages/{page}', [PageController::class, 'show'])->name('pages.show');

    Route::put('pages/{page}/title', [PageTitleController::class, 'update'])->name('pages.title.update');

    Route::put('pages/{page}/content', [PageContentController::class, 'update'])
        ->middleware('throttle:page-saves')
        ->name('pages.content.update');

    Route::put('pages/{page}/placement', [PagePlacementController::class, 'update'])->name('pages.placement.update');

    Route::delete('pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');
});
