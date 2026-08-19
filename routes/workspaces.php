<?php

declare(strict_types=1);

use App\Http\Controllers\Workspace\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 * The {workspace} parameter is a slug. ResolveCurrentWorkspace reads it as one and 404s
 * before the controller runs if it is anything else.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

    Route::post('workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');

    Route::get('workspaces/{workspace}/settings', [WorkspaceController::class, 'edit'])->name('workspaces.edit');
    Route::put('workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
});
