<?php

declare(strict_types=1);

use App\Http\Controllers\Search\SearchController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('search', [SearchController::class, 'index'])->name('search.index');
});
