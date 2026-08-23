<?php

declare(strict_types=1);

use App\Http\Controllers\Task\MyTasksController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('my-tasks', [MyTasksController::class, 'index'])->name('my-tasks.index');
});
