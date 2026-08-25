<?php

declare(strict_types=1);

namespace App\Http\Controllers\Project;

use App\Domain\Project\Actions\StarProject;
use App\Domain\Project\Actions\UnstarProject;
use App\Domain\Project\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Starring a project, and stopping.
 *
 * The actor stars on their own behalf and nobody else's. Both methods answer with a redirect
 * back, because a star changes a control and the sidebar beside it rather than a screen — and
 * with no toast, because the row moving to the top says it happened.
 */
class ProjectStarController extends Controller
{
    public function store(Request $request, Project $project, StarProject $starProject): RedirectResponse
    {
        Gate::authorize('view', $project);

        $starProject->handle($project, $this->actor($request));

        return back();
    }

    /**
     * No `view` check here: somebody who has lost access must still be able to clear the project
     * out of their own sidebar, which is the Action's rule.
     */
    public function destroy(Request $request, Project $project, UnstarProject $unstarProject): RedirectResponse
    {
        $unstarProject->handle($project, $this->actor($request));

        return back();
    }
}
