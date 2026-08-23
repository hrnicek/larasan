<?php

declare(strict_types=1);

use App\Http\Controllers\Notification\InboxController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');

    Route::put('inbox/{notification}/read', [InboxController::class, 'read'])
        ->whereUuid('notification')
        ->name('inbox.read');

    // One request rather than one per row: a full inbox is exactly when a request per line is
    // most expensive and least useful.
    Route::put('inbox/read', [InboxController::class, 'readAll'])->name('inbox.read-all');
});
