<?php

declare(strict_types=1);

namespace App\Http\Controllers\Placement;

use App\Domain\Placement\Actions\AttachTaskToProject;
use App\Domain\Placement\Actions\DetachTaskFromProject;
use App\Domain\Placement\Actions\MoveTaskInProject;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Project\Models\Project;
use App\Http\Controllers\Controller;
use App\Http\Requests\Placement\MovePlacementRequest;
use App\Http\Requests\Placement\StorePlacementRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class PlacementController extends Controller
{
    public function store(StorePlacementRequest $request, Project $project, AttachTaskToProject $attach): RedirectResponse
    {
        $task = $project->workspace->tasks()->whereKey($request->string('task')->toString())->firstOrFail();

        $attach->handle($task, $project, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task added to the project.')]);

        return back();
    }

    public function move(MovePlacementRequest $request, TaskProjectMembership $placement, MoveTaskInProject $move): RedirectResponse
    {
        $move->handle($placement, $this->actor($request), $request->targetSection(), $request->target());

        return back();
    }

    public function destroy(Request $request, TaskProjectMembership $placement, DetachTaskFromProject $detach): RedirectResponse
    {
        Gate::authorize('delete', $placement);

        $task = $placement->task;
        $project = $placement->project;

        $detach->handle($task, $project, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task removed from the project.')]);

        return back();
    }
}
