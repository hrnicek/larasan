<?php

declare(strict_types=1);

namespace App\Http\Controllers\CustomField;

use App\Domain\CustomField\Actions\AttachFieldToProject;
use App\Domain\CustomField\Actions\DetachFieldFromProject;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomField\AttachProjectFieldRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Which of the workspace's fields a project shows.
 *
 * The other half of `CustomFieldController`: a workspace defines more than any one board wants on
 * its screen, so attaching is a decision per project. Detaching takes the column off and **keeps
 * the answers** — deleting the field is what removes them, and the two operations differ in
 * exactly that.
 */
class ProjectCustomFieldController extends Controller
{
    public function store(
        AttachProjectFieldRequest $request,
        Project $project,
        AttachFieldToProject $attachField,
    ): RedirectResponse {
        $attachField->handle($project, $this->field($request, $project), $this->actor($request));

        return back();
    }

    public function destroy(
        Request $request,
        Project $project,
        CustomField $field,
        DetachFieldFromProject $detachField,
    ): RedirectResponse {
        Gate::authorize(Capability::CustomFieldManage->value, $project->workspace);

        $detachField->handle($project, $field, $this->actor($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Field removed from this project. The answers are kept.'),
        ]);

        return back();
    }

    private function field(Request $request, Project $project): CustomField
    {
        return CustomField::query()
            ->whereKey($request->string('field')->value())
            ->where('workspace_id', $project->workspace_id)
            ->first() ?? abort(404);
    }
}
