<?php

declare(strict_types=1);

use App\Domain\Comment\Models\Comment;
use App\Domain\Workspace\Queries\CurrentWorkspace;
use App\Http\Controllers\Comment\CommentController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * `{comment}` resolves inside the workspace the actor is currently in, as every other bound
 * model here does: a comment from another tenant is indistinguishable from one that does not
 * exist. Soft-deleted comments are not bound at all — a removed comment can be read in a
 * thread, but there is nothing left to address.
 *
 * Whether the actor may read the subject, edit their own words or remove somebody else's is
 * the policy's answer, not the binding's.
 */
Route::bind('comment', function (string $id): Comment {
    $user = request()->user();

    if (! $user instanceof User) {
        abort(404);
    }

    $workspace = app(CurrentWorkspace::class)->for($user) ?? abort(404);

    return Comment::query()->whereKey($id)->where('workspace_id', $workspace->id)->first() ?? abort(404);
});

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
