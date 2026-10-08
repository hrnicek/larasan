<?php

declare(strict_types=1);

use App\Http\Controllers\Placement\PlacementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->whereUuid(['project', 'placement'])->group(function (): void {
    Route::post('projects/{project}/placements', [PlacementController::class, 'store'])->name('placements.store');

    Route::put('placements/{placement}/move', [PlacementController::class, 'move'])
        ->middleware('throttle:task-moves')
        ->name('placements.move');

    Route::delete('placements/{placement}', [PlacementController::class, 'destroy'])->name('placements.destroy');
});
