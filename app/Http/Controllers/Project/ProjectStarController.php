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

class ProjectStarController extends Controller
{
    public function store(Request $request, Project $project, StarProject $starProject): RedirectResponse
    {
        Gate::authorize('view', $project);

        $starProject->handle($project, $this->actor($request));

        return back();
    }

    /**
     * No `view` check: someone who has lost access must still be able to unstar.
     */
    public function destroy(Request $request, Project $project, UnstarProject $unstarProject): RedirectResponse
    {
        $unstarProject->handle($project, $this->actor($request));

        return back();
    }
}
