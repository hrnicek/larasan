<?php

declare(strict_types=1);

use App\Domain\File\Models\Attachment;
use App\Domain\Workspace\Queries\CurrentWorkspace;
use App\Http\Controllers\File\AttachmentController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * `{attachment}` resolves through its file's workspace, which is the workspace the actor is
 * currently in: an attachment from another tenant is indistinguishable from one that does not
 * exist. A soft-deleted file does not resolve at all — it has stopped being reachable, which is
 * the whole point of removing it that way (TASK-120-007).
 *
 * Whether the actor may open the thing it hangs from is the policy's answer, not the binding's,
 * and it is asked in the controller: a storage path is never a capability (ADR-0007).
 */
Route::bind('attachment', function (string $id): Attachment {
    $user = request()->user();

    if (! $user instanceof User) {
        abort(404);
    }

    $workspace = app(CurrentWorkspace::class)->for($user) ?? abort(404);

    return Attachment::query()
        ->whereKey($id)
        ->whereHas('file', fn ($files) => $files->where('workspace_id', $workspace->id))
        ->first() ?? abort(404);
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    // Uploading is expensive in a way commenting is not — it writes bytes — so it carries the
    // same kind of bound the other costly endpoints do.
    Route::post('tasks/{task}/attachments', [AttachmentController::class, 'store'])
        ->whereUuid('task')
        ->middleware('throttle:attachments')
        ->name('tasks.attachments.store');

    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->whereUuid('attachment')
        ->name('attachments.download');
});
