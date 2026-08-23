<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Http\Controllers\Comment\CommentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    // A comment is written about a subject, so the subject is in the URL; afterwards it has an
    // id of its own and the subject is no longer needed to address it.
    // Limited because a comment is cheap here and expensive elsewhere: each one notifies the
    // task's followers and its assignee, so a loop fills other people's inboxes.
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
