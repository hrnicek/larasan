<?php

declare(strict_types=1);

use App\Http\Controllers\Section\SectionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->whereUuid(['project', 'section'])->group(function (): void {
    Route::post('projects/{project}/sections', [SectionController::class, 'store'])->name('sections.store');
    Route::put('sections/{section}', [SectionController::class, 'update'])->name('sections.update');

    Route::put('sections/{section}/move', [SectionController::class, 'move'])
        ->middleware('throttle:task-moves')
        ->name('sections.move');

    Route::delete('sections/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');
});
