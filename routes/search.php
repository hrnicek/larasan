<?php

declare(strict_types=1);

use App\Http\Controllers\Search\SavedSearchController;
use App\Http\Controllers\Search\SearchController;
use App\Http\Controllers\Search\SearchSuggestionsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('search', [SearchController::class, 'index'])->name('search.index');

    /*
     * The palette's own endpoint: one keystroke, one answer, no screen change. Rate limited
     * because it is the one address in this application a held-down key can call.
     */
    Route::get('search/suggestions', SearchSuggestionsController::class)
        ->middleware('throttle:search')
        ->name('search.suggestions');

    /*
     * The searches somebody kept. A bookmark, so it belongs to the person who made it and to
     * nobody else — `SavedSearchPolicy` is what says so on the way out.
     */
    Route::post('search/saved', [SavedSearchController::class, 'store'])->name('search.saved.store');
    Route::delete('search/saved/{savedSearch}', [SavedSearchController::class, 'destroy'])->name('search.saved.destroy');
});
