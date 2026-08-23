<?php

declare(strict_types=1);

use App\Domain\Tag\Models\Tag;
use App\Domain\Workspace\Queries\CurrentWorkspace;
use App\Http\Controllers\Tag\TagController;
use App\Http\Controllers\Tag\TaskTagController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * `{tag}` resolves inside the workspace the actor is currently in: a tag from another tenant is
 * indistinguishable from one that does not exist.
 */
Route::bind('tag', function (string $id): Tag {
    $user = request()->user();

    if (! $user instanceof User) {
        abort(404);
    }

    $workspace = app(CurrentWorkspace::class)->for($user) ?? abort(404);

    return Tag::query()->whereKey($id)->where('workspace_id', $workspace->id)->first() ?? abort(404);
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('tags', [TagController::class, 'store'])->name('tags.store');
    Route::put('tags/{tag}', [TagController::class, 'update'])->whereUuid('tag')->name('tags.update');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->whereUuid('tag')->name('tags.destroy');

    // On the task, because putting a tag on something is an edit of that thing.
    Route::post('tasks/{task}/tags', [TaskTagController::class, 'store'])
        ->whereUuid('task')
        ->name('tasks.tags.store');

    Route::delete('tasks/{task}/tags/{tag}', [TaskTagController::class, 'destroy'])
        ->whereUuid(['task', 'tag'])
        ->name('tasks.tags.destroy');
});
