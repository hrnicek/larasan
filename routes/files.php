<?php

declare(strict_types=1);

use App\Http\Controllers\File\AttachmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    // Uploading is expensive in a way commenting is not — it writes bytes — so it carries the
    // same kind of bound the other costly endpoints do.
    Route::post('tasks/{task}/attachments', [AttachmentController::class, 'store'])
        ->whereUuid('task')
        ->middleware('throttle:attachments')
        ->name('tasks.attachments.store');

    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->whereUuid('attachment')
        ->name('attachments.download');

    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])
        ->whereUuid('attachment')
        ->name('attachments.destroy');
});
