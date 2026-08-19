<?php

declare(strict_types=1);

use App\Http\Controllers\Workspace\WorkspaceController;
use App\Http\Controllers\Workspace\WorkspaceMemberController;
use Illuminate\Support\Facades\Route;

/*
 * The {workspace} parameter is a slug. ResolveCurrentWorkspace reads it as one and 404s
 * before the controller runs if it is anything else.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
    Route::post('workspaces', [WorkspaceController::class, 'store'])
        ->middleware('throttle:workspace-creation')
        ->name('workspaces.store');

    Route::post('workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');

    /*
     * Workspace settings act on the workspace the request is already in, so the route
     * carries no parameter — docs/ui/settings.md specifies /settings/workspace, and the
     * resolution middleware supplies the workspace from the actor's current one.
     */
    Route::get('settings/members', [WorkspaceMemberController::class, 'index'])->name('workspaces.members');
    /*
     * Throttled: each invitation queues mail to an address the actor names, so without a
     * limit any account is a mail cannon pointed at any registered address, sending from
     * this domain.
     */
    Route::post('settings/members', [WorkspaceMemberController::class, 'store'])
        ->middleware('throttle:workspace-invitations')
        ->name('workspaces.members.store');
    Route::put('settings/members/{membership}', [WorkspaceMemberController::class, 'update'])->name('workspaces.members.update');
    Route::delete('settings/members/{membership}', [WorkspaceMemberController::class, 'destroy'])->name('workspaces.members.destroy');

    Route::get('settings/workspace', [WorkspaceController::class, 'edit'])->name('workspaces.edit');
    Route::put('settings/workspace', [WorkspaceController::class, 'update'])->name('workspaces.update');
});
