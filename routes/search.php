<?php

declare(strict_types=1);

use App\Http\Controllers\Search\SavedSearchController;
use App\Http\Controllers\Search\SearchController;
use App\Http\Controllers\Search\SearchSuggestionsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('search', [SearchController::class, 'index'])->name('search.index');

    Route::get('search/suggestions', SearchSuggestionsController::class)
        ->middleware('throttle:search')
        ->name('search.suggestions');

    Route::post('search/saved', [SavedSearchController::class, 'store'])->name('search.saved.store');
    Route::delete('search/saved/{savedSearch}', [SavedSearchController::class, 'destroy'])->name('search.saved.destroy');
});
