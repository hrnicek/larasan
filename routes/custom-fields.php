<?php

declare(strict_types=1);

use App\Http\Controllers\CustomField\TaskCustomFieldController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::put('tasks/{task}/custom-fields/{field}', [TaskCustomFieldController::class, 'update'])
        ->whereUuid(['task', 'field'])
        ->name('tasks.custom-fields.update');
});
