<?php

declare(strict_types=1);

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
});
