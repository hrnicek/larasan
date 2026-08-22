<?php

declare(strict_types=1);

use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Section\Models\Section;
use App\Domain\Workspace\Queries\ResolveWorkspaceForUser;
use App\Http\Controllers\Section\SectionController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * `{section}` resolves through the projects the actor may see in the workspace they are
 * currently in — the same rule `routes/projects.php` binds `{project}` with, one level
 * down. A section of a project they cannot open is indistinguishable from one that does
 * not exist.
 */
Route::bind('section', function (string $id): Section {
    $user = request()->user();

    if (! $user instanceof User) {
        abort(404);
    }

    $workspace = app(ResolveWorkspaceForUser::class)($user) ?? abort(404);

    $projects = app(VisibleProjectsForUser::class)
        ->query($workspace, $user, includeArchived: true)
        ->select('projects.id');

    return Section::query()->whereKey($id)->whereIn('project_id', $projects)->first() ?? abort(404);
});

Route::middleware(['auth', 'verified'])->whereUuid(['project', 'section'])->group(function (): void {
    Route::post('projects/{project}/sections', [SectionController::class, 'store'])->name('sections.store');
    Route::put('sections/{section}', [SectionController::class, 'update'])->name('sections.update');

    // A move is its own endpoint: it takes an anchor, never a position (ADR-0009).
    Route::put('sections/{section}/move', [SectionController::class, 'move'])->name('sections.move');

    Route::delete('sections/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');
});
