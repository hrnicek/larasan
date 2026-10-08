<?php

declare(strict_types=1);

use App\Http\Controllers\Comment\CommentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('tasks/{task}/comments', [CommentController::class, 'store'])
        ->whereUuid('task')
        ->middleware('throttle:comments')
        ->name('tasks.comments.store');

    Route::put('comments/{comment}', [CommentController::class, 'update'])
        ->whereUuid('comment')
        ->middleware('throttle:comments')
        ->name('comments.update');

    Route::delete('comments/{comment}', [CommentController::class, 'destroy'])
        ->whereUuid('comment')
        ->name('comments.destroy');
});
