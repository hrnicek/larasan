<?php

declare(strict_types=1);

use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Workspace\Queries\ResolveWorkspaceForUser;
use App\Http\Controllers\Placement\PlacementController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * `{placement}` resolves through the projects the actor may see in the workspace they are
 * currently in, exactly as `{section}` does — a card in a project they cannot open is
 * indistinguishable from one that does not exist. Within a project the policy answers.
 */
Route::bind('placement', function (string $id): TaskProjectMembership {
    $user = request()->user();

    if (! $user instanceof User) {
        abort(404);
    }

    $workspace = app(ResolveWorkspaceForUser::class)($user) ?? abort(404);

    $projects = app(VisibleProjectsForUser::class)
        ->query($workspace, $user, includeArchived: true)
        ->select('projects.id');

    return TaskProjectMembership::query()
        ->whereKey($id)
        ->whereIn('project_id', $projects)
        ->first() ?? abort(404);
});

Route::middleware(['auth', 'verified'])->whereUuid(['project', 'placement'])->group(function (): void {
    Route::post('projects/{project}/placements', [PlacementController::class, 'store'])->name('placements.store');

    // A move is its own endpoint: it takes a column and an anchor, never a position
    // (ADR-0009).
    Route::put('placements/{placement}/move', [PlacementController::class, 'move'])->name('placements.move');

    Route::delete('placements/{placement}', [PlacementController::class, 'destroy'])->name('placements.destroy');
});
