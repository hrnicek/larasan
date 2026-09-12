<?php

declare(strict_types=1);

use App\Http\Controllers\File\AttachmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('tasks/{task}/attachments', [AttachmentController::class, 'store'])
        ->whereUuid('task')
        ->middleware('throttle:attachments')
        ->name('tasks.attachments.store');

    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->whereUuid('attachment')
        ->name('attachments.download');

    // A separate route so an inline disposition can never be requested from the download endpoint.
    Route::get('attachments/{attachment}/preview', [AttachmentController::class, 'preview'])
        ->whereUuid('attachment')
        ->name('attachments.preview');

    Route::put('attachments/{attachment}/move', [AttachmentController::class, 'move'])
        ->whereUuid('attachment')
        ->middleware('throttle:task-moves')
        ->name('attachments.move');

    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])
        ->whereUuid('attachment')
        ->name('attachments.destroy');
});
