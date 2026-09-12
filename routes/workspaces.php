<?php

declare(strict_types=1);

use App\Http\Controllers\Workspace\WorkspaceController;
use App\Http\Controllers\Workspace\WorkspaceInvitationController;
use App\Http\Controllers\Workspace\WorkspaceMemberController;
use Illuminate\Support\Facades\Route;

// Outside the auth group so invitees without an account can follow the link.
// The signature expires with the invitation.
Route::get('invitations/{membership}', [WorkspaceInvitationController::class, 'show'])
    ->middleware('signed')
    ->name('workspaces.invitations.show');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('invitations/{membership}/accept', [WorkspaceInvitationController::class, 'accept'])
        ->name('workspaces.invitations.accept');
    Route::post('invitations/{membership}/decline', [WorkspaceInvitationController::class, 'decline'])
        ->name('workspaces.invitations.decline');

    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
    Route::post('workspaces', [WorkspaceController::class, 'store'])
        ->middleware('throttle:workspace-creation')
        ->name('workspaces.store');

    Route::post('workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');

    Route::get('settings/members', [WorkspaceMemberController::class, 'index'])->name('workspaces.members');
    Route::post('settings/members', [WorkspaceMemberController::class, 'store'])
        ->middleware('throttle:workspace-invitations')
        ->name('workspaces.members.store');
    Route::put('settings/members/{membership}', [WorkspaceMemberController::class, 'update'])->name('workspaces.members.update');
    Route::post('settings/members/{membership}/resend', [WorkspaceMemberController::class, 'resend'])
        ->middleware('throttle:workspace-invitations')
        ->name('workspaces.members.resend');
    Route::delete('settings/members/{membership}', [WorkspaceMemberController::class, 'destroy'])->name('workspaces.members.destroy');

    Route::get('settings/workspace', [WorkspaceController::class, 'edit'])->name('workspaces.edit');
    Route::put('settings/workspace', [WorkspaceController::class, 'update'])->name('workspaces.update');
});
