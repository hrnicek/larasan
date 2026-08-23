<?php

declare(strict_types=1);

use App\Domain\CustomField\Models\CustomField;
use App\Domain\Workspace\Queries\CurrentWorkspace;
use App\Http\Controllers\CustomField\TaskCustomFieldController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * `{field}` resolves inside the workspace the actor is currently in: a field from another tenant
 * is indistinguishable from one that does not exist. Whether it is shown on this task is the
 * Action's answer, because that is a question about the task rather than about the field.
 */
Route::bind('field', function (string $id): CustomField {
    $user = request()->user();

    if (! $user instanceof User) {
        abort(404);
    }

    $workspace = app(CurrentWorkspace::class)->for($user) ?? abort(404);

    return CustomField::query()->whereKey($id)->where('workspace_id', $workspace->id)->first() ?? abort(404);
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::put('tasks/{task}/custom-fields/{field}', [TaskCustomFieldController::class, 'update'])
        ->whereUuid(['task', 'field'])
        ->name('tasks.custom-fields.update');
});
