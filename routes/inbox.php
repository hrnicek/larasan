<?php

declare(strict_types=1);

use App\Domain\Workspace\Queries\CurrentWorkspace;
use App\Http\Controllers\Notification\InboxController;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;

/*
 * `{notification}` resolves only inside the reader's own inbox, in the workspace they are
 * currently in. Somebody else's notification is a 404 rather than a 403: it is addressed to one
 * person, so its existence is not something anybody else is entitled to confirm.
 *
 * That also means the Actions behind these routes need no authorization of their own — there is
 * no way to name a notification that is not yours.
 */
Route::bind('notification', function (string $id): DatabaseNotification {
    $user = request()->user();

    if (! $user instanceof User) {
        abort(404);
    }

    $workspace = app(CurrentWorkspace::class)->for($user) ?? abort(404);

    return DatabaseNotification::query()
        ->whereKey($id)
        ->where('workspace_id', $workspace->id)
        ->where('notifiable_type', 'user')
        ->where('notifiable_id', $user->id)
        ->first() ?? abort(404);
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');

    Route::put('inbox/{notification}/read', [InboxController::class, 'read'])
        ->whereUuid('notification')
        ->name('inbox.read');

    // One request rather than one per row: a full inbox is exactly when a request per line is
    // most expensive and least useful.
    Route::put('inbox/read', [InboxController::class, 'readAll'])->name('inbox.read-all');
});
