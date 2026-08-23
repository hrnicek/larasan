<?php

declare(strict_types=1);

use App\Http\Controllers\Notification\InboxController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
});
