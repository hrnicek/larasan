<?php

declare(strict_types=1);

namespace App\Http\Controllers\Project;

use App\Domain\Project\Actions\GrantProjectAccess;
use App\Domain\Project\Actions\RevokeProjectAccess;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\GrantProjectAccessRequest;
use App\Http\Requests\Project\UpdateProjectAccessRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ProjectMemberController extends Controller
{
    public function store(
        GrantProjectAccessRequest $request,
        Project $project,
        GrantProjectAccess $grantAccess,
    ): RedirectResponse {
        $member = User::query()->whereKey($request->input('user'))->first() ?? abort(404);

        $grantAccess->handle(
            $project,
            $this->actor($request),
            $member,
            ProjectAccessLevel::from((string) $request->string('access_level')),
        );

        return back();
    }

    public function update(
        UpdateProjectAccessRequest $request,
        Project $project,
        string $membership,
        GrantProjectAccess $grantAccess,
    ): RedirectResponse {
        $grantAccess->handle(
            $project,
            $this->actor($request),
            $this->membership($project, $membership)->user,
            ProjectAccessLevel::from((string) $request->string('access_level')),
        );

        return back();
    }

    public function destroy(
        Request $request,
        Project $project,
        string $membership,
        RevokeProjectAccess $revokeAccess,
    ): RedirectResponse {
        Gate::authorize('manageMembers', $project);

        $revokeAccess->handle($project, $this->actor($request), $this->membership($project, $membership));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Access removed. What they wrote stays.'),
        ]);

        return back();
    }

    /**
     * Resolved within the project rather than by implicit binding, so another project's id is a 404.
     */
    private function membership(Project $project, string $id): ProjectMembership
    {
        return $project->memberships()->whereKey($id)->first() ?? abort(404);
    }
}
