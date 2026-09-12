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
    public function update(
        UpdateProjectNameRequest $request,
        Project $project,
        RenameProject $renameProject,
    ): RedirectResponse {
        $renameProject->handle($project, $this->actor($request), $request->string('name')->toString());

        return back();
    }
}
