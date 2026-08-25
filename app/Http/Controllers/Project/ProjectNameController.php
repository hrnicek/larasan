<?php

declare(strict_types=1);

namespace App\Http\Controllers\Project;

use App\Domain\Project\Actions\RenameProject;
use App\Domain\Project\Models\Project;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\UpdateProjectNameRequest;
use Illuminate\Http\RedirectResponse;

class ProjectNameController extends Controller
{
    /**
     * Back rather than to a named route, as the appearance endpoint does: renaming is offered
     * from the sidebar, which is on every screen, and a redirect of its own would move somebody
     * off the board they were reading. The new name in the sidebar and in the header is the
     * confirmation, so there is no toast either.
     */
    public function update(
        UpdateProjectNameRequest $request,
        Project $project,
        RenameProject $renameProject,
    ): RedirectResponse {
        $renameProject->handle($project, $this->actor($request), $request->string('name')->toString());

        return back();
    }
}
